<?php

declare(strict_types=1);

namespace FancyCowork\Store;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Engine session state <-> `cowork_sessions` + `cowork_participants`, through
 * the query builder. No Eloquent model and no foreign key to any host table:
 * every ref is a string the host chose.
 *
 * A participant who leaves keeps their row with `left_at` set; joining again
 * writes a new row, so the table is also a record of who was in the session.
 */
final class SessionStore
{
    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private readonly ?string $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }

    /**
     * Run `$work` with the session row locked, so two intents for one session
     * never read the same state and both commit.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function locked(string $id, Closure $work): mixed
    {
        return $this->db()->transaction(function () use ($id, $work): mixed {
            $this->db()->table('cowork_sessions')->where('id', $id)->lockForUpdate()->value('id');

            return $work();
        });
    }

    /** @return array<string, mixed>|null */
    public function read(string $id): ?array
    {
        $row = $this->db()->table('cowork_sessions')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $participants = [];
        $rows = $this->db()->table('cowork_participants')->where('session_id', $id)->whereNull('left_at')->orderBy('id')->get();
        foreach ($rows as $p) {
            $participant = ['ref' => (string) $p->ref];
            if ($p->label !== null) {
                $participant['label'] = (string) $p->label;
            }
            if ($p->attributes !== null) {
                $participant['attributes'] = json_decode((string) $p->attributes, true);
            }
            if ($p->acting_for !== null) {
                $participant['actingFor'] = (string) $p->acting_for;
            }
            $participant['route'] = (string) $p->view_route;
            $participant['revision'] = (int) $p->view_revision;
            $participant['follows'] = $p->follows === null ? null : (string) $p->follows;
            $participant['joinedAt'] = (int) $p->joined_at;
            $participants[(string) $p->ref] = $participant;
        }

        return [
            'id' => (string) $row->id,
            'state' => (string) $row->state,
            'starter' => (string) $row->starter,
            'consented' => (bool) $row->consented,
            'revision' => (int) $row->revision,
            'createdAt' => (int) $row->created_ms,
            'endedAt' => $row->ended_ms === null ? null : (int) $row->ended_ms,
            'participants' => $participants,
            'control' => self::decodeMap($row->control),
            'last' => self::decodeMap($row->last),
            'rate' => self::decodeMap($row->rate),
        ];
    }

    /**
     * Insert a new session, or bring an existing one in line with `$state`.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $config  a JSON-safe snapshot, kept for re-sync and audit
     */
    public function write(array $state, array $config, int $now): void
    {
        $db = $this->db();
        $values = [
            'state' => $state['state'],
            'starter' => $state['starter'],
            'consented' => $state['consented'],
            'revision' => $state['revision'],
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'control' => self::encodeMap($state['control']),
            'last' => self::encodeMap($state['last']),
            'rate' => self::encodeMap($state['rate']),
            'created_ms' => $state['createdAt'],
            'ended_ms' => $state['endedAt'],
            'updated_at' => gmdate('Y-m-d H:i:s', intdiv($now, 1000)),
        ];
        if ($db->table('cowork_sessions')->where('id', $state['id'])->exists()) {
            $db->table('cowork_sessions')->where('id', $state['id'])->update($values);
        } else {
            $db->table('cowork_sessions')->insert(['id' => $state['id'], ...$values, 'created_at' => $values['updated_at']]);
        }

        $active = $db->table('cowork_participants')->where('session_id', $state['id'])->whereNull('left_at')->get()->keyBy(fn (object $row): string => (string) $row->ref);
        foreach ($active as $ref => $row) {
            if (! isset($state['participants'][$ref])) {
                $db->table('cowork_participants')->where('id', $row->id)->update(['left_at' => $now]);
            }
        }
        foreach ($state['participants'] as $p) {
            $columns = [
                'acting_for' => $p['actingFor'] ?? null,
                'label' => $p['label'] ?? null,
                'attributes' => isset($p['attributes']) ? json_encode($p['attributes'], JSON_THROW_ON_ERROR) : null,
                'view_route' => $p['route'],
                'view_revision' => $p['revision'],
                'follows' => $p['follows'],
            ];
            $existing = $active->get((string) $p['ref']);
            if ($existing !== null) {
                $db->table('cowork_participants')->where('id', $existing->id)->update($columns);
            } else {
                $db->table('cowork_participants')->insert([
                    'session_id' => $state['id'],
                    'ref' => (string) $p['ref'],
                    ...$columns,
                    'joined_at' => $p['joinedAt'],
                ]);
            }
        }
    }

    /** Sessions this ref started since `$since` (ms), for the starts-per-minute limit. */
    public function countStarts(string $ref, int $since): int
    {
        return $this->db()->table('cowork_sessions')->where('starter', $ref)->where('created_ms', '>', $since)->count();
    }

    /** @param  array<array-key, mixed>  $map */
    private static function encodeMap(array $map): string
    {
        return $map === [] ? '{}' : json_encode($map, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function decodeMap(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}

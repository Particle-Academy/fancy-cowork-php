<?php

declare(strict_types=1);

/*
 * Break one clause at a time; the suite must notice every one. A suite written
 * alongside its implementation passes on its first run, and a check never seen
 * to fail cannot be told apart from one that cannot. Each file is restored in a
 * `finally`. Exits 1 if any mutation survives, 2 if a mutation's target text is
 * missing (the mutation list has drifted from the code).
 *
 *     php tests/sabotage.php
 */

$root = dirname(__DIR__);

/** @var list<array{0: string, 1: string, 2: string, 3: string}> [name, file, search, replace] */
$mutations = [
    ['identity prelude skipped', 'src/Engine/Engine.php', 'if (! $host->identity) {', 'if (false) {'],
    ['membership never refuses', 'src/Engine/Pipeline.php', "if (! isset(\$ctx->session['participants'][\$ref]) || ! self::askMembers(\$host, (string) \$ref)) {", 'if (false) {'],
    ['members truthy accepted', 'src/Engine/Pipeline.php', 'return ($host->members)($ref) === true;', 'return (bool) ($host->members)($ref);'],
    ['authorize truthy accepted', 'src/Engine/Pipeline.php', "if (\$answer === true || (is_array(\$answer) && (\$answer['allow'] ?? null) === true)) {", 'if ($answer) {'],
    ['view ability not asked', 'src/Engine/Pipeline.php', "\$abilities[] = 'view:'.\$ctx->view;", ';'],
    ['drive not asked', 'src/Engine/Pipeline.php', 'if ($ctx->view !== $ctx->from) {', 'if (false) {'],
    ['intent size off by one', 'src/Engine/Pipeline.php', "} elseif (Wire::jsonBytes(\$intent) > \$size['intentBytes']) {", "} elseif (Wire::jsonBytes(\$intent) > \$size['intentBytes'] + 1) {"],
    ['app size unchecked', 'src/Engine/Pipeline.php', "if (Wire::jsonBytes(\$intent['payload']) > \$size['appBytes']) {", 'if (false) {'],
    ['same origin unchecked', 'src/Engine/Pipeline.php', "if (\$intent['kind'] === 'visit' && ! Wire::isSameOriginPath(\$intent['url'])) {", 'if (false) {'],
    ['backslash path allowed', 'src/Engine/Wire.php', "|| str_contains(\$url, '\\\\')", ''],
    ['mask unchecked', 'src/Engine/Pipeline.php', "if (\$intent['kind'] === 'field' && Glob::any(\$limits['mask'], \$intent['handle'])) {", 'if (false) {'],
    ['view-only unchecked', 'src/Engine/Pipeline.php', "in_array(\$intent['kind'], ['field', 'click', 'submit'], true) && Glob::any(\$limits['viewOnly'], \$intent['handle'])", 'false'],
    ['deny rules ignored', 'src/Engine/Pipeline.php', 'if (self::ruleMatches((array) $rule, $ctx, $intent, true) === true) {', 'if (false) {'],
    ['allow rules not required', 'src/Engine/Pipeline.php', 'return $applicable ? Reasons::refuse(Reasons::LIMIT, 403) : $next();', 'return $next();'],
    ['undefined when fails open', 'src/Engine/Pipeline.php', 'return $isDeny;', 'return ! $isDeny;'],
    ['revision unchecked', 'src/Engine/Pipeline.php', "if (\$view !== null && (\$ctx->envelope['revision'] ?? -1) < \$view['revision']) {", 'if (false) {'],
    ['baton holder unchecked', 'src/Engine/Pipeline.php', "if (\$mode === 'baton' && SessionState::holderOf(\$ctx->session, \$ctx->view)['holder'] !== \$ctx->from) {", 'if (false) {'],
    ['grant by a non-holder', 'src/Engine/Pipeline.php', "if (in_array(\$intent['op'], ['grant', 'release'], true) && \$ctx->from !== \$state['holder']) {", 'if (false) {'],
    ['revoke by a non-owner', 'src/Engine/Pipeline.php', "if (\$intent['op'] === 'revoke' && \$ctx->from !== \$ctx->view) {", 'if (false) {'],
    ['control intents outside baton', 'src/Engine/Pipeline.php', "if (\$mode !== 'baton') {", 'if (false) {'],
    ['ranked hand-back late', 'src/Engine/Pipeline.php', '$ctx->now - $at >= $idleMs', '$ctx->now - $at > $idleMs'],
    ['intent rate unchecked', 'src/Engine/Pipeline.php', "if (self::within(\$mine['all'], \$ctx->now, 1000) >= \$rate['intentsPerSecond']) {", 'if (false) {'],
    ['visit rate unchecked', 'src/Engine/Pipeline.php', "self::within(\$mine['visit'], \$ctx->now, 1000) >= \$rate['visitsPerSecond']", 'false'],
    ['app rate unchecked', 'src/Engine/Pipeline.php', "self::within(\$mine['app'], \$ctx->now, 60000) >= \$rate['appPerMinute']", 'false'],
    ['field without a value accepted', 'src/Engine/Wire.php', "&& array_key_exists('value', \$intent)", ''],
    ['consent never pending', 'src/Engine/Engine.php', "if (\$config['consent'] === 'accept' && ! \$state['consented'] && \$input['from'] !== self::subjectOf(\$state, \$config)) {", 'if (false) {'],
    ['no participant cap', 'src/Engine/Engine.php', "if (count(\$state['participants']) >= \$config['maxParticipants']) {", 'if (false) {'],
    ['anyone consents', 'src/Engine/Engine.php', "if (\$input['from'] !== self::subjectOf(\$state, \$host->config)) {", 'if (false) {'],
    ['end without the ability', 'src/Engine/Engine.php', "if (\$ability = self::abilityGate(\$host, \$input['from'], 'end')) {", 'if (false) {'],
    ['leaver keeps the baton', 'src/Engine/Engine.php', "\$next['control'][\$view] = ['holder' => \$view, 'requests' => \$requests];", "\$next['control'][\$view] = ['holder' => \$ref, 'requests' => \$requests];"],
    ['no start rate limit', 'src/CoworkManager.php', "\$this->store->countStarts(\$who->ref, \$at - 60_000) >= \$config['rate']['startsPerMinute']", 'false'],
    ['sender taken from the body', 'src/CoworkManager.php', "'session' => \$state['id'],\n                'from' => \$who->ref,", "'session' => \$state['id'],\n                'from' => \$body['from'] ?? \$who->ref,"],
    ['channel ignores members()', 'src/CoworkManager.php', 'if ($joined === null || ($this->members)(Session::fromState($state), $who) !== true) {', 'if ($joined === null) {'],
    ['any private participant channel', 'src/CoworkManager.php', 'return $match[1] === $who->ref ? true : CoworkResponse::refuse(403, Reasons::CHANNEL);', 'return true;'],
    ['ended session channel admitted', 'src/CoworkManager.php', "if (\$state['state'] === 'ended') {\n            return CoworkResponse::refuse(410, Reasons::ENDED);", "if (false) {\n            return CoworkResponse::refuse(410, Reasons::ENDED);"],
    ['sender socket not excluded', 'src/CoworkManager.php', "'socket' => \$exceptSocket]", "'socket' => null]"],
    ['host error leaks as success', 'src/CoworkManager.php', 'return CoworkResponse::refuse(500, Reasons::HOST_ERROR);', 'return new CoworkResponse(200, []);'],
    ['guest signature unchecked', 'src/GuestTokens.php', 'if (! hash_equals($expected, $signature)) {', 'if (false) {'],
    ['guest token expiry late', 'src/GuestTokens.php', "(\$now ?? time()) >= \$claims['exp']", "(\$now ?? time()) > \$claims['exp']"],
    ['audit row loses actingFor', 'src/Audit/AuditRecorder.php', "'acting_for' => \$event->actingFor,", "'acting_for' => null,"],
    ['relayed event names the wrong sender', 'src/Engine/Pipeline.php', "\$event = ['type' => 'IntentRelayed', 'session' => \$ctx->session['id'], 'ref' => \$ctx->from];", "\$event = ['type' => 'IntentRelayed', 'session' => \$ctx->session['id'], 'ref' => (string) \$ctx->view];"],
    ['a host model appears', 'src/Session.php', 'final readonly class Session', '/* App\\Models\\User */ final readonly class Session'],
    ['pusher: parameters unsorted', 'src/Broadcasting/PusherProtocol.php', 'ksort($pairs, SORT_STRING);', ';'],
    ['pusher: method not uppercased', 'src/Broadcasting/PusherProtocol.php', 'hash_hmac(\'sha256\', strtoupper($method)."\n{$path}\n{$query}", $secret)', 'hash_hmac(\'sha256\', $method."\n{$path}\n{$query}", $secret)'],
    ['pusher: signs over its own signature', 'src/Broadcasting/PusherProtocol.php', "if (\$name === 'auth_signature') {\n                continue;\n            }", "if (false) {\n                continue;\n            }"],
    ['pusher: body md5 of the wrong bytes', 'src/Broadcasting/PusherProtocol.php', "'body_md5' => md5(\$body),", "'body_md5' => md5(trim(\$body, '{}')),"],
    ['pusher: no size limit', 'src/Broadcasting/PusherHttpClient.php', 'if (strlen($data) > self::MAX_DATA_BYTES) {', 'if (false) {'],
    ['pusher: socket id unchecked', 'src/Broadcasting/PusherHttpClient.php', "\$socket = \$socketId !== null && preg_match('/^\\d+\\.\\d+\$/', \$socketId) === 1 ? \$socketId : null;", '$socket = $socketId;'],
    ['pusher: no channel chunking', 'src/Broadcasting/PusherHttpClient.php', 'array_chunk(array_values($channels), self::MAX_CHANNELS)', '[array_values($channels)]'],
    ['pusher: refusal ignored', 'src/Broadcasting/PusherHttpClient.php', 'if ($response !== null && $response->successful()) {', 'if (true) {'],
    ['pusher: socket not excluded', 'src/Broadcasting/PusherBroadcaster.php', "\$this->client->trigger(\$this->formatChannels(\$channels), (string) \$event, \$payload, is_string(\$socket) ? \$socket : null);", "\$this->client->trigger(\$this->formatChannels(\$channels), (string) \$event, \$payload, null);"],
    ['pusher: presence id from the user only', 'src/Broadcasting/PusherBroadcaster.php', "\$userId = is_array(\$result) ? (\$result['id'] ?? \$result['ref'] ?? null) : null;", '$userId = null;'],
    ['pusher: driver never registered', 'src/CoworkServiceProvider.php', "        \$this->registerPusherDriver();\n", ''],
    ['presence: kinds unknown', 'src/Engine/Wire.php', "'control', 'app', 'cursor', 'activity'];", "'control', 'app'];"],
    ['presence: cursor route unchecked', 'src/Engine/Wire.php', "if (! self::isRoute(\$intent['route'] ?? null)) {\n            return false;\n        }\n        if (array_key_exists('anchor', \$intent)) {", "if (array_key_exists('anchor', \$intent)) {"],
    ['presence: route need not be a path', 'src/Engine/Wire.php', "|| ! str_starts_with(\$value, '/')) {", ') {'],
    ['presence: cursor fraction unbounded', 'src/Engine/Wire.php', "if (! self::isFinite(\$v) || \$v < 0 || \$v > 1) {", "if (! self::isFinite(\$v) || \$v < 0) {"],
    ['presence: negative coordinates accepted', 'src/Engine/Wire.php', "if (! self::isFinite(\$v) || \$v < 0) {\n                return false;\n            }\n        }\n\n        return true;", "if (! self::isFinite(\$v)) {\n                return false;\n            }\n        }\n\n        return true;"],
    ['presence: activity needs no boolean', 'src/Engine/Wire.php', "'activity' => self::isHandle(\$intent['handle'] ?? null) && is_bool(\$intent['active'] ?? null),", "'activity' => self::isHandle(\$intent['handle'] ?? null),"],
    ['presence: cursor rate unchecked', 'src/Engine/Pipeline.php', "if (self::within(\$mine['cursor'] ?? [], \$ctx->now, 1000) >= \$rate['cursorsPerSecond']) {", 'if (false) {'],
    ['presence: cursor spends the intent budget', 'src/Engine/Pipeline.php', "                return Reasons::refuse(Reasons::RATE, 429);\n            }\n\n            return \$next();\n        }\n", "                return Reasons::refuse(Reasons::RATE, 429);\n            }\n        }\n"],
    ['presence: cursor committed to the intent bucket', 'src/Engine/Engine.php', "            \$rate['cursor'] = \$keep(\$rate['cursor'] ?? [], 1000);\n        } else {\n            \$rate['all'] = \$keep(\$rate['all'], 1000);\n        }", "            \$rate['cursor'] = \$keep(\$rate['cursor'] ?? [], 1000);\n        }\n        \$rate['all'] = \$keep(\$rate['all'], 1000);"],
    ['presence: default cursor rate changed', 'src/Engine/Config.php', "'cursorsPerSecond' => 10],", "'cursorsPerSecond' => 100],"],
    ['keep-alive: handler rebuilt per publish', 'src/Broadcasting/PusherHttpClient.php', '$this->handler ??= ', '$this->handler = '],
    ['keep-alive: handler never set', 'src/Broadcasting/PusherHttpClient.php', "                    ->setHandler(\$this->handler)\n", ''],
    ['ranked: session scope ignored', 'src/Engine/Pipeline.php', "\$views = (\$ctx->config['control']['scope'] ?? 'view') === 'session'", "\$views = false"],
    ['ranked: every scope is session', 'src/Engine/Pipeline.php', "\$views = (\$ctx->config['control']['scope'] ?? 'view') === 'session'", "\$views = true"],
    ['ranked: session blocker not the latest', 'src/Engine/Pipeline.php', "\$blockedUntil = max(\$blockedUntil ?? 0, \$at + \$idleMs);\n                }", "\$blockedUntil = \$blockedUntil ?? \$at + \$idleMs;\n                }"],
    ['identity: a second identity joins', 'src/Engine/Engine.php', "                : self::refused(\$state, Reasons::refuse(Reasons::REF_IN_USE, 409));", "                : new Result(Reasons::allow(), \$state, []);"],
    ['identity: a second identity sends', 'src/Engine/Pipeline.php', "        if (! SessionState::sameIdentity(\$ctx->session['participants'][\$ctx->from], \$ctx->actingFor)) {\n            return Reasons::refuse(Reasons::REF_IN_USE, 409);\n        }\n", ''],
    ['identity: null and a value are the same', 'src/Engine/SessionState.php', "return (\$participant['actingFor'] ?? null) === (\$actingFor ?? null);", 'return true;'],
    ['presence: nobody expires', 'src/Presence.php', "            if (\$this->online(\$participant, \$now)) {\n                continue;\n            }", "            continue;"],
    ['presence: expiry off by one', 'src/Presence.php', "return \$now - self::lastSeen(\$participant) < \$this->settings['idle_ms'];", "return \$now - self::lastSeen(\$participant) <= \$this->settings['idle_ms'];"],
    ['presence: expiry has no reason', 'src/Presence.php', "\$event['type'] === 'PeerLeft' ? [...\$event, 'reason' => 'expired'] : \$event;", '$event;'],
    ['presence: starter leaving ends nothing', 'src/Presence.php', "if (\$this->settings['end_when_starter_leaves'] && ! isset(\$state['participants'][\$state['starter']])) {", 'if (false) {'],
    ['presence: the host cannot keep a starterless session', 'src/Presence.php', "if (\$this->settings['end_when_starter_leaves'] && ! isset(\$state['participants'][\$state['starter']])) {", "if (! isset(\$state['participants'][\$state['starter']])) {"],
    ['presence: empty sessions live forever', 'src/Presence.php', "} elseif (\$state['participants'] === [] && \$now - (\$emptySince ?? (int) \$state['createdAt']) >= \$this->settings['empty_ms']) {", '} elseif (false) {'],
    ['presence: a heartbeat does not count', 'src/Presence.php', "        \$state['participants'][\$ref]['lastSeen'] = \$now;\n", ''],
    ['presence: another identity keeps a ref alive', 'src/Presence.php', "if (\$participant === null || ! SessionState::sameIdentity(\$participant, \$actingFor)) {\n            return \$state;", "if (\$participant === null) {\n            return \$state;"],
    ['presence: lastSeen never stored', 'src/Store/SessionStore.php', "                'last_seen_ms' => \$p['lastSeen'] ?? \$p['joinedAt'],\n", ''],
    ['presence: sweep refused away', 'src/CoworkManager.php', "                    if (\$state !== \$original) {\n                        \$this->store->write(\$state, self::snapshot(\$config), \$now);\n                    }\n", ''],
    ['presence: heartbeat for strangers', 'src/CoworkManager.php', "            if (\$participant === null || (\$host->members !== null && (\$host->members)(\$who->ref) !== true)) {", 'if (false) {'],
    ['presence: prune does nothing', 'src/Console/PruneCommand.php', "            \$counts = \$cowork->prune(\$id);\n", "            \$counts = ['expired' => 0, 'ended' => false];\n"],
    ['presence: idle default weakened', 'config/fancy-cowork.php', "'idle_ms' => 120_000,", "'idle_ms' => 86_400_000,"],
    ['end reason not set by end', 'src/Engine/Engine.php', "\$next['endReason'] = 'ended';", ''],
    ['end reason not set by presence', 'src/Presence.php', "\$state['endReason'] = \$reason;", ''],
    ['re-sync drops the end reason', 'src/CoworkManager.php', "'endReason' => \$state['state'] === 'ended' ? (string) (\$state['endReason'] ?? 'ended') : null,", "'endReason' => null,"],
    ['end reason not stored', 'src/Store/SessionStore.php', "'end_reason' => \$state['endReason'] ?? null,", ''],
    ['429 not retried', 'src/Broadcasting/PusherHttpClient.php', 'if ($retryable && $attempt < $this->retries) {', 'if (false) {'],
    ['5xx not retried', 'src/Broadcasting/PusherHttpClient.php', 'private const array RETRYABLE = [429, 500, 502, 503, 504];', 'private const array RETRYABLE = [429];'],
    ['failed connection not retried', 'src/Broadcasting/PusherHttpClient.php', '$retryable = $response === null ? $unsent : in_array(', '$retryable = $response === null ? false : in_array('],
    ['publish resent after it was sent', 'src/Broadcasting/PusherHttpClient.php', '$retryable = $response === null ? $unsent : in_array(', '$retryable = $response === null ? true : in_array('],
    ['non-connect failure taken as unsent', 'src/Broadcasting/PusherHttpClient.php', "        if (! \$cause instanceof ConnectException) {\n            return false;", "        if (! \$cause instanceof ConnectException) {\n            return true;"],
    ['Guzzle 7 bytes-sent ignored', 'src/Broadcasting/PusherHttpClient.php', "return ! is_numeric(\$sent) || (int) \$sent === 0;", 'return true;'],
    ['publish settings ignored', 'src/Broadcasting/PusherHttpClient.php', "            \$setting('retries'),\n            \$setting('retry_budget_ms'),", "            null,\n            null,"],
    ['undelivered broadcast dropped silently', 'src/CoworkManager.php', '            $this->reportUndelivered($channel, $event, $e);', ''],
    ['retries unbounded', 'src/Broadcasting/PusherHttpClient.php', 'public const int MAX_RETRIES = 2;', 'public const int MAX_RETRIES = 5;'],
    ['wait budget ignored', 'src/Broadcasting/PusherHttpClient.php', 'if ($waited + $wait <= $this->retryBudgetMs) {', 'if (true) {'],
    ['Retry-After ignored', 'src/Broadcasting/PusherHttpClient.php', 'return (int) $header * 1000;', 'return 0;'],
    ['body not ASCII', 'src/Broadcasting/PusherHttpClient.php', "                JSON_UNESCAPED_SLASHES,\n            );", "                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,\n            );"],
    ['reserved refs accepted', 'src/Engine/Wire.php', "return self::isHandle(\$value) && ! in_array(\$value, self::RESERVED_REFS, true);", 'return self::isHandle($value);'],
    ['sender ref unchecked', 'src/Engine/Engine.php', "if (! Wire::isRef(\$from)) {\n            return Reasons::refuse(Reasons::UNIDENTIFIED, 401);", "if (false) {\n            return Reasons::refuse(Reasons::UNIDENTIFIED, 401);"],
];

$pest = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/pestphp/pest/bin/pest').' --bail';
$survivors = [];
$missing = [];
foreach ($mutations as [$name, $file, $search, $replace]) {
    $path = $root.'/'.$file;
    $original = (string) file_get_contents($path);
    // Compare on LF so a CRLF checkout does not hide a target.
    $text = str_replace("\r\n", "\n", $original);
    $at = strpos($text, $search);
    if ($at === false) {
        $missing[] = "{$name} ({$file})";

        continue;
    }
    try {
        file_put_contents($path, substr_replace($text, $replace, $at, strlen($search)));
        exec($pest.' 2>&1', $output, $code);
        $output = [];
        if ($code === 0) {
            $survivors[] = $name;
        }
        echo ($code === 0 ? 'SURVIVED ' : 'killed   ').$name.PHP_EOL;
    } finally {
        file_put_contents($path, $original);
    }
}

echo sprintf('%d mutations, %d killed, %d survived, %d missing', count($mutations), count($mutations) - count($survivors) - count($missing), count($survivors), count($missing)).PHP_EOL;
foreach ($missing as $name) {
    echo 'MISSING TARGET: '.$name.PHP_EOL;
}
exit($missing !== [] ? 2 : ($survivors === [] ? 0 : 1));

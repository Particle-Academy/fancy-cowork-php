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

<?php

// Writes dev/SCHEMA.md from three freshly migrated scratch schemas. Run through dev/scripts/schema-doc.sh.
// Env: DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, and the three schema names in SCHEMA_DOC_WAREHOUSE/CONTROL/ACCOUNTS.

$pdo = new PDO(
    'mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: 3306).';charset=utf8mb4',
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$sections = [
    'Warehouse schema' => [getenv('SCHEMA_DOC_WAREHOUSE'), 'One per warehouse. `database/migrations/`.'],
    'Control schema' => [getenv('SCHEMA_DOC_CONTROL'), 'Instance-wide: which warehouses exist. `database/migrations/control/`.'],
    'Accounts schema' => [getenv('SCHEMA_DOC_ACCOUNTS'), 'Instance-wide: logins and memberships. `database/migrations/accounts/`.'],
];

function rows(PDO $pdo, string $sql, array $args): array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);

    return array_map(fn ($r) => array_change_key_case($r, CASE_LOWER), $st->fetchAll());
}

$out = <<<'MD'
# Database schema reference

**Generated — do not edit by hand.** Regenerate with `dev/scripts/schema-doc.sh` after adding or changing a migration, and commit the result
with it. It migrates throwaway databases (`test_reliefinventory_schemadoc*`) from scratch, so it shows what the migrations produce, not what any
live database looks like. Columns: `name type` then `?` nullable, `=default`, `PK`, `auto`. `FK` lines show `column → table.column (on delete)`.

MD;

foreach ($sections as $title => [$schema, $blurb]) {
    $out .= "\n## {$title}\n\n{$blurb}\n";
    $tables = array_column(rows($pdo, "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name", [$schema]), 'table_name');

    foreach ($tables as $t) {
        if (in_array($t, ['migrations'], true)) {
            continue;
        }
        $out .= "\n### {$t}\n\n```\n";
        foreach (rows($pdo, 'SELECT column_name, column_type, is_nullable, column_default, column_key, extra FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$schema, $t]) as $c) {
            $type = preg_replace('/\b(big|small|medium)?int\(\d+\)/', '$1int', str_replace('tinyint(1)', 'bool', $c['column_type']));
            $line = $c['column_name'].' '.preg_replace('/\btinyint\(\d+\)/', 'tinyint', $type);
            $line .= $c['is_nullable'] === 'YES' ? '?' : '';
            if ($c['column_default'] !== null && $c['column_default'] !== 'NULL') {
                $line .= ' ='.trim($c['column_default'], "'");
            }
            if ($c['column_key'] === 'PRI') {
                $line .= ' PK';
            }
            if (str_contains($c['extra'], 'auto_increment')) {
                $line .= ' auto';
            }
            $out .= $line."\n";
        }
        foreach (rows($pdo, 'SELECT k.constraint_name, k.column_name, k.referenced_table_name rt, k.referenced_column_name rc, r.delete_rule dr FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name WHERE k.table_schema = ? AND k.table_name = ? ORDER BY k.constraint_name, k.ordinal_position', [$schema, $t]) as $f) {
            $out .= "FK {$f['column_name']} → {$f['rt']}.{$f['rc']} (".strtolower($f['dr']).")\n";
        }
        $idx = [];
        foreach (rows($pdo, 'SELECT index_name, non_unique, column_name FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? ORDER BY index_name, seq_in_index', [$schema, $t]) as $i) {
            if ($i['index_name'] === 'PRIMARY') {
                continue;
            }
            $idx[$i['index_name']]['u'] = ! $i['non_unique'];
            $idx[$i['index_name']]['c'][] = $i['column_name'];
        }
        foreach ($idx as $i) {
            if (! $i['u']) {
                continue; // plain indexes are noise here; unique ones are rules
            }
            $out .= 'UNIQUE ('.implode(', ', $i['c']).")\n";
        }
        $out .= "```\n";
    }
}

file_put_contents(__DIR__.'/../SCHEMA.md', $out);
echo "Wrote dev/SCHEMA.md\n";

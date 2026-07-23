<?php
declare(strict_types=1);

$root = sys_get_temp_dir()
    . '/auto_photo_dense_download_'
    . bin2hex(random_bytes(5));
define('AUTO_PHOTO_SPARSE_OUTPUT_ROOT', $root);

if (!class_exists('mysqli')) {
    class mysqli {}
    class mysqli_stmt {}
    class mysqli_result {}
}

require_once __DIR__
    . '/../libs/auto_photo_dense_download_scope_lib.php';

function apdd_ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function apdd_copy(array $value): array
{
    return unserialize(serialize($value));
}

class ApddResult extends mysqli_result
{
    private int $position = 0;

    public function __construct(private array $rows)
    {
    }

    public function fetch_assoc(): array|null|false
    {
        return $this->rows[$this->position++] ?? null;
    }
}

class ApddStatement extends mysqli_stmt
{
    public function __construct(
        private ApddDb $db,
        private string $sql
    ) {
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        return true;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function get_result(): mysqli_result|false
    {
        if (str_contains($this->sql, 'FROM capture_bundles')) {
            return new ApddResult(
                $this->db->bundle === null ? [] : [$this->db->bundle]
            );
        }
        if (str_contains(
            $this->sql,
            'id=? AND order_id=? AND capture_session_id=?'
        )) {
            return new ApddResult(
                $this->db->prepare === null ? [] : [$this->db->prepare]
            );
        }
        if (str_contains(
            $this->sql,
            'FROM sfm_remote_jobs WHERE id=? LIMIT 1'
        )) {
            return new ApddResult(
                $this->db->sparse === null ? [] : [$this->db->sparse]
            );
        }
        return new ApddResult([]);
    }

    public function close(): true
    {
        return true;
    }
}

class ApddDb extends mysqli
{
    public function __construct(
        public ?array $sparse,
        public ?array $bundle,
        public ?array $prepare
    ) {
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        return new ApddStatement($this, $query);
    }
}

function apdd_fixture(): array
{
    $bundle = [
        'id' => 7,
        'order_id' => 30,
        'capture_session_id' => 63,
        'app_bundle_uuid' => 'bundle-uuid',
    ];
    $prepare = [
        'id' => 745,
        'order_id' => 30,
        'capture_session_id' => 63,
        'pipeline_run_id' => null,
        'job_type' => AUTO_PHOTO_PREPARE_JOB_TYPE,
        'remote_job_id' => 9001,
        'status' => 'DONE',
        'output_path' => auto_photo_sparse_output_path(9001),
        'parameters_json' => json_encode([
            'source_type' => 'auto_photo_bundle',
            'pipeline_mode' => 'prepare',
            'already_selected_frames' => true,
            'capture_bundle_id' => 7,
            'app_bundle_uuid' => 'bundle-uuid',
            'input_images' => 12,
        ]),
    ];
    $sparse = [
        'id' => 746,
        'order_id' => 30,
        'capture_session_id' => 63,
        'pipeline_run_id' => null,
        'job_type' => 'COLMAP_SPARSE',
        'remote_job_id' => 9002,
        'parent_remote_job_id' => 9001,
        'status' => 'DONE',
        'output_path' => auto_photo_sparse_output_path(9002),
        'parameters_json' => json_encode([
            'source_type' => 'auto_photo_prepare',
            'standalone_sparse' => true,
            'prepare_job_id' => 745,
            'prepare_remote_job_id' => 9001,
            'capture_bundle_id' => 7,
            'app_bundle_uuid' => 'bundle-uuid',
        ]),
    ];
    $dense = [
        'id' => 981,
        'order_id' => 30,
        'capture_session_id' => 63,
        'pipeline_run_id' => null,
        'job_type' => 'COLMAP_RECONSTRUCTION_PREVIEW',
        'remote_job_id' => 9200,
        'parent_remote_job_id' => 9002,
        'status' => 'DONE',
        'reconstruction_mode' => 'preview',
        'output_path' => auto_photo_sparse_output_path(9200)
            . '/merged/merged_fused.ply',
        'parameters_json' => json_encode([
            'source_type' => 'auto_photo_sparse',
            'standalone_auto_photo_dense' => true,
            'dense_only' => true,
            'sparse_db_job_id' => 746,
            'sparse_job_id' => 9002,
            'sparse_remote_job_id' => 9002,
            'model_id' => 0,
        ]),
    ];

    return compact('bundle', 'prepare', 'sparse', 'dense');
}

function apdd_write_files(): void
{
    mkdir(
        auto_photo_sparse_output_path(9002) . '/colmap',
        0775,
        true
    );
    file_put_contents(
        auto_photo_sparse_output_path(9002)
            . '/colmap/sparse_components.json',
        json_encode([
            'models' => [
                [
                    'model_id' => 0,
                    'registered_images' => 12,
                    'points3D_count' => 100,
                ],
                [
                    'model_id' => 1,
                    'registered_images' => 11,
                    'points3D_count' => 90,
                ],
            ],
        ])
    );

    mkdir(
        auto_photo_sparse_output_path(9200) . '/merged',
        0775,
        true
    );
    file_put_contents(
        auto_photo_sparse_output_path(9200)
            . '/merged/merged_fused.ply',
        "ply\n"
        . "format ascii 1.0\n"
        . "element vertex 1\n"
        . "property float x\n"
        . "property float y\n"
        . "property float z\n"
        . "end_header\n"
        . "0 0 0\n"
    );
}

function apdd_rm(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            apdd_rm($path . '/' . $entry);
        }
    }
    rmdir($path);
}

function apdd_resolve(array $fixture): ?array
{
    return auto_photo_dense_download_resolve(
        new ApddDb(
            $fixture['sparse'],
            $fixture['bundle'],
            $fixture['prepare']
        ),
        $fixture['dense']
    );
}

try {
    apdd_write_files();
    $fixture = apdd_fixture();

    $resolved = apdd_resolve($fixture);
    apdd_ok(is_array($resolved), 'valid model 0 resolves');
    apdd_ok(($resolved['model_id'] ?? null) === 0, 'model 0 preserved');
    apdd_ok(
        ($resolved['path'] ?? null) === realpath(
            auto_photo_sparse_output_path(9200)
                . '/merged/merged_fused.ply'
        ),
        'exact dense output resolved'
    );

    apdd_ok(
        auto_photo_dense_download_is_candidate($fixture['dense']),
        'standalone dense is candidate'
    );
    $legacy = apdd_copy($fixture['dense']);
    $legacy['parameters_json'] = json_encode([
        'pipeline_run_id' => 10,
        'settings' => ['dense' => ['max_image_size' => 1024]],
    ]);
    apdd_ok(
        !auto_photo_dense_download_is_candidate($legacy),
        'legacy Video SfM remains outside standalone resolver'
    );
    $partial = apdd_copy($fixture['dense']);
    $partial['parameters_json'] = json_encode([
        'source_type' => 'auto_photo_sparse',
        'dense_only' => true,
    ]);
    apdd_ok(
        auto_photo_dense_download_is_candidate($partial)
        && apdd_resolve([
            ...$fixture,
            'dense' => $partial,
        ]) === null,
        'partial Auto Photo markers fail closed'
    );

    foreach ([
        ['dense', 'status', 'RUNNING'],
        ['dense', 'reconstruction_mode', 'hq'],
        ['dense', 'pipeline_run_id', 10],
        ['dense', 'parent_remote_job_id', 9009],
        ['dense', 'output_path', '/tmp/wrong.ply'],
        ['sparse', 'order_id', 31],
        ['sparse', 'capture_session_id', 64],
        ['sparse', 'remote_job_id', 9010],
        ['sparse', 'job_type', 'COLMAP_DENSE'],
        ['sparse', 'pipeline_run_id', 1],
        ['sparse', 'status', 'RUNNING'],
        ['sparse', 'parent_remote_job_id', 9999],
        ['prepare', 'remote_job_id', 9011],
        ['prepare', 'pipeline_run_id', 1],
        ['prepare', 'status', 'RUNNING'],
    ] as [$section, $key, $value]) {
        $bad = apdd_copy($fixture);
        $bad[$section][$key] = $value;
        apdd_ok(
            apdd_resolve($bad) === null,
            "{$section}.{$key} mismatch rejected"
        );
    }

    $bad = apdd_copy($fixture);
    $bad['dense']['parameters_json'] = '{';
    apdd_ok(apdd_resolve($bad) === null, 'malformed dense JSON rejected');

    $bad = apdd_copy($fixture);
    $denseParameters = json_decode(
        $bad['dense']['parameters_json'],
        true
    );
    $denseParameters['model_id'] = 9;
    $bad['dense']['parameters_json'] = json_encode($denseParameters);
    apdd_ok(apdd_resolve($bad) === null, 'unknown model rejected');

    $bad = apdd_copy($fixture);
    $bad['sparse'] = null;
    apdd_ok(apdd_resolve($bad) === null, 'missing sparse row rejected');

    $bad = apdd_copy($fixture);
    $bad['bundle']['app_bundle_uuid'] = 'wrong';
    apdd_ok(apdd_resolve($bad) === null, 'bundle mismatch rejected');

    $bad = apdd_copy($fixture);
    $bad['prepare'] = null;
    apdd_ok(apdd_resolve($bad) === null, 'missing prepare row rejected');

    $bad = apdd_copy($fixture);
    $bad['prepare']['parameters_json'] = json_encode([
        'source_type' => 'auto_photo_bundle',
        'pipeline_mode' => 'prepare',
        'already_selected_frames' => false,
        'capture_bundle_id' => 7,
        'app_bundle_uuid' => 'bundle-uuid',
        'input_images' => 12,
    ]);
    apdd_ok(apdd_resolve($bad) === null, 'prepare markers rejected');

    $manifest = auto_photo_sparse_output_path(9002)
        . '/colmap/sparse_components.json';
    $manifestBackup = (string) file_get_contents($manifest);
    file_put_contents($manifest, '{');
    apdd_ok(apdd_resolve($fixture) === null, 'malformed manifest rejected');
    file_put_contents($manifest, $manifestBackup);

    $ply = auto_photo_sparse_output_path(9200)
        . '/merged/merged_fused.ply';
    $plyBackup = (string) file_get_contents($ply);
    file_put_contents($ply, '');
    apdd_ok(apdd_resolve($fixture) === null, 'empty PLY rejected');
    file_put_contents($ply, "not ply\n");
    apdd_ok(apdd_resolve($fixture) === null, 'invalid PLY rejected');
    file_put_contents($ply, $plyBackup);

    $outside = $root . '/outside.ply';
    file_put_contents($outside, $plyBackup);
    unlink($ply);
    symlink($outside, $ply);
    apdd_ok(apdd_resolve($fixture) === null, 'PLY symlink rejected');
    unlink($ply);
    file_put_contents($ply, $plyBackup);

    $endpoint = (string) file_get_contents(
        __DIR__ . '/../www/api/sfm_remote_job_status.php'
    );
    apdd_ok(
        str_contains(
            $endpoint,
            "require_once dirname(__DIR__, 2) "
            . ". '/libs/auto_photo_dense_download_scope_lib.php';"
        ),
        'endpoint loads production resolver'
    );
    apdd_ok(
        str_contains(
            $endpoint,
            'auto_photo_dense_download_is_candidate($job)'
        )
        && str_contains(
            $endpoint,
            'auto_photo_dense_download_resolve($dbcnx,$job)'
        ),
        'endpoint uses production resolver'
    );

    echo "OK\n";
} finally {
    apdd_rm($root);
}

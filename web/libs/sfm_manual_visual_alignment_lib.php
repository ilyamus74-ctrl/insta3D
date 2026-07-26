<?php
declare(strict_types=1);

require_once __DIR__ . '/sfm_manual_alignment_lib.php';

const SFM_MANUAL_VISUAL_MERGE_TYPE =
    'manual_visual_sim3_dense_ply';
const SFM_MANUAL_VISUAL_MERGE_METHOD =
    'manual_visual_transform_sim3';
const SFM_MANUAL_VISUAL_INCREMENTAL_MERGE_TYPE =
    'manual_visual_incremental_sim3_dense_ply';
const SFM_MANUAL_VISUAL_INCREMENTAL_MERGE_METHOD =
    'manual_visual_incremental_transform_sim3';

function sfm_manual_visual_normalize_matrix4(mixed $matrix): array
{
    if (!is_array($matrix) || count($matrix) !== 4) {
        throw new RuntimeException(
            'matrix4 must contain four rows'
        );
    }

    $normalized = [];
    foreach ($matrix as $rowIndex => $row) {
        if (!is_array($row) || count($row) !== 4) {
            throw new RuntimeException(
                'matrix4 row ' . $rowIndex
                . ' must contain four values'
            );
        }

        $normalizedRow = [];
        foreach ($row as $value) {
            if (!is_numeric($value)) {
                throw new RuntimeException(
                    'matrix4 contains a non-numeric value'
                );
            }
            $number = (float)$value;
            if (!is_finite($number)) {
                throw new RuntimeException(
                    'matrix4 contains a non-finite value'
                );
            }
            $normalizedRow[] = $number;
        }
        $normalized[] = $normalizedRow;
    }

    $last = $normalized[3];
    if (
        abs($last[0]) > 1e-7
        || abs($last[1]) > 1e-7
        || abs($last[2]) > 1e-7
        || abs($last[3] - 1.0) > 1e-7
    ) {
        throw new RuntimeException(
            'matrix4 last row must be [0,0,0,1]'
        );
    }

    $columns = [];
    for ($column = 0; $column < 3; $column++) {
        $columns[$column] = [
            $normalized[0][$column],
            $normalized[1][$column],
            $normalized[2][$column],
        ];
    }

    $norms = array_map(
        static fn(array $column): float => sqrt(
            $column[0] ** 2
            + $column[1] ** 2
            + $column[2] ** 2
        ),
        $columns
    );
    $scale = array_sum($norms) / 3.0;

    if (
        !is_finite($scale)
        || $scale < 0.0001
        || $scale > 10000.0
    ) {
        throw new RuntimeException(
            'Visual uniform scale is outside 0.0001..10000'
        );
    }

    foreach ($norms as $norm) {
        if (abs($norm - $scale) > max(1e-7, $scale * 0.002)) {
            throw new RuntimeException(
                'matrix4 contains non-uniform scale or shear'
            );
        }
    }

    $rotation = [];
    for ($row = 0; $row < 3; $row++) {
        $rotation[$row] = [];
        for ($column = 0; $column < 3; $column++) {
            $rotation[$row][$column] =
                $normalized[$row][$column] / $scale;
        }
    }

    $determinant = sfm_manual_rotation_det($rotation);
    if (abs($determinant - 1.0) > 0.01) {
        throw new RuntimeException(
            'Visual rotation determinant is not close to +1'
        );
    }

    for ($left = 0; $left < 3; $left++) {
        for ($right = 0; $right < 3; $right++) {
            $dot = 0.0;
            for ($axis = 0; $axis < 3; $axis++) {
                $dot +=
                    $rotation[$axis][$left]
                    * $rotation[$axis][$right];
            }
            $expected = $left === $right ? 1.0 : 0.0;
            if (abs($dot - $expected) > 0.01) {
                throw new RuntimeException(
                    'Visual rotation matrix is not orthonormal'
                );
            }
        }
    }

    return [
        'matrix4' => $normalized,
        'scale' => $scale,
        'rotation' => $rotation,
        'translation' => [
            $normalized[0][3],
            $normalized[1][3],
            $normalized[2][3],
        ],
        'rotation_determinant' => $determinant,
    ];
}

function sfm_manual_visual_transform_hash(array $normalized): string
{
    $canonical = json_encode(
        $normalized['matrix4'],
        JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
    );
    if (!is_string($canonical)) {
        throw new RuntimeException(
            'Cannot canonicalize visual matrix'
        );
    }
    return hash('sha256', $canonical);
}

function sfm_manual_visual_draft_dir(
    int $orderId,
    string $anchorKind,
    int $anchorId,
    string $sourceKind,
    int $sourceId
): string {
    $name = sprintf(
        'manual_visual_alignment_order_%d_anchor_%s_%d_source_%s_%d',
        $orderId,
        preg_replace('/[^a-z0-9_]+/i', '_', $anchorKind),
        $anchorId,
        preg_replace('/[^a-z0-9_]+/i', '_', $sourceKind),
        $sourceId
    );

    return sfm_manual_output_root() . '/' . $name;
}

function sfm_manual_visual_fingerprint(
    int $orderId,
    string $mergeType,
    array $anchor,
    array $source,
    string $transformHash,
    string $anchorMd5,
    string $sourceMd5,
    string $outputMd5
): string {
    return hash(
        'sha256',
        implode('|', [
            $orderId,
            $mergeType,
            (string)($anchor['kind'] ?? 'remote'),
            (int)($anchor['merge_id'] ?? 0),
            (int)($anchor['db_job_id'] ?? 0),
            (int)($anchor['remote_job_id'] ?? 0),
            (int)($source['db_job_id'] ?? 0),
            (int)($source['remote_job_id'] ?? 0),
            $transformHash,
            $anchorMd5,
            $sourceMd5,
            $outputMd5,
        ])
    );
}

function sfm_manual_visual_cleanup_staging(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (
        [
            'visual_transform.json',
            'source_visual_aligned_to_anchor.ply',
            'manual_visual_merged_dense_cloud.ply',
            'visual_merge_result.json',
        ]
        as $name
    ) {
        @unlink($directory . '/' . $name);
    }
    @rmdir($directory);
}

function sfm_manual_visual_save(
    mysqli $db,
    int $orderId,
    string $anchorKind,
    int $anchorId,
    string $sourceKind,
    int $sourceId,
    array $transformState,
    int $userId,
    string $role
): array {
    sfm_manual_require_idempotency_schema($db);
    sfm_manual_ensure_order_write_access(
        $db,
        $orderId,
        $userId,
        $role
    );

    if (
        !in_array($anchorKind, ['remote', 'merge'], true)
        || $sourceKind !== 'remote'
    ) {
        throw new RuntimeException(
            'Visual assembly supports merge|remote Anchor '
            . 'and remote Moving source'
        );
    }
    if (
        $anchorKind === $sourceKind
        && $anchorId === $sourceId
    ) {
        throw new RuntimeException(
            'Anchor and Moving source must be different'
        );
    }

    $anchor = sfm_manual_resolve_alignment_input(
        $db,
        $orderId,
        $anchorKind,
        $anchorId
    );
    $source = sfm_manual_resolve_remote_model(
        $db,
        $orderId,
        $sourceKind,
        $sourceId
    );

    if (
        (int)$anchor['capture_session_id']
        !== (int)$source['capture_session_id']
    ) {
        throw new RuntimeException(
            'Anchor and Moving source must belong '
            . 'to the same capture session'
        );
    }

    if ($anchorKind === 'merge') {
        foreach (
            ($anchor['leaf_source_jobs'] ?? [])
            as $leaf
        ) {
            if (
                (int)($leaf['remote_job_id'] ?? 0)
                === (int)$source['remote_job_id']
            ) {
                throw new RuntimeException(
                    'Moving source is already included '
                    . 'in this assembly'
                );
            }
        }
    }

    $matrixInput = $transformState['matrix4']
        ?? ($transformState['transform']['matrix4'] ?? null);
    $normalized = sfm_manual_visual_normalize_matrix4(
        $matrixInput
    );
    $transformHash = sfm_manual_visual_transform_hash(
        $normalized
    );

    $draftDirectory = sfm_manual_visual_draft_dir(
        $orderId,
        $anchorKind,
        $anchorId,
        $sourceKind,
        $sourceId
    );
    if (
        !is_dir($draftDirectory)
        && !mkdir($draftDirectory, 0775, true)
        && !is_dir($draftDirectory)
    ) {
        throw new RuntimeException(
            'Cannot create visual-alignment draft directory'
        );
    }
    sfm_manual_safe_dir($draftDirectory);

    $lock = fopen($draftDirectory . '/compute.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException(
            'Cannot open visual-alignment lock'
        );
    }

    $inTransaction = false;
    $stagingDirectory = '';

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException(
                'Cannot lock visual-alignment draft'
            );
        }

        $stagingDirectory = $draftDirectory
            . '/.save_'
            . getmypid()
            . '_'
            . bin2hex(random_bytes(4));
        if (
            !mkdir($stagingDirectory, 0775, true)
            && !is_dir($stagingDirectory)
        ) {
            throw new RuntimeException(
                'Cannot create visual-alignment staging directory'
            );
        }

        $transformPath =
            $stagingDirectory . '/visual_transform.json';
        $transformPayload = [
            'schema_version' => 1,
            'created_at' => gmdate('c'),
            'order_id' => $orderId,
            'anchor' => [
                'kind' => $anchorKind,
                'id' => $anchorId,
                'ply' => $anchor['ply'],
            ],
            'moving_source' => [
                'kind' => $sourceKind,
                'id' => $sourceId,
                'ply' => $source['ply'],
            ],
            'method' => SFM_MANUAL_VISUAL_MERGE_METHOD,
            'matrix4' => $normalized['matrix4'],
            'scale' => $normalized['scale'],
            'rotation' => $normalized['rotation'],
            'translation' => $normalized['translation'],
            'rotation_determinant' =>
                $normalized['rotation_determinant'],
            'transform_sha256' => $transformHash,
            'browser_state' => $transformState,
        ];
        sfm_manual_atomic_write_json(
            $transformPath,
            $transformPayload
        );

        $script = dirname(__DIR__)
            . '/remote_station/scripts/'
            . 'manual_pointcloud_matrix_merge.py';
        if (!is_file($script)) {
            throw new RuntimeException(
                'Visual point-cloud merge script not found'
            );
        }

        $command = implode(
            ' ',
            array_map(
                'escapeshellarg',
                [
                    '/usr/bin/python3',
                    $script,
                    '--anchor',
                    $anchor['ply'],
                    '--source',
                    $source['ply'],
                    '--transform-json',
                    $transformPath,
                    '--output-dir',
                    $stagingDirectory,
                ]
            )
        );

        $commandOutput = [];
        $exitCode = 0;
        exec(
            $command . ' 2>&1',
            $commandOutput,
            $exitCode
        );
        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Visual point-cloud merge failed: '
                . implode(
                    "\n",
                    array_slice($commandOutput, -100)
                )
            );
        }

        $alignedDraft = sfm_manual_safe_realpath(
            $stagingDirectory
                . '/source_visual_aligned_to_anchor.ply'
        );
        $mergedDraft = sfm_manual_safe_realpath(
            $stagingDirectory
                . '/manual_visual_merged_dense_cloud.ply'
        );
        $resultDraft = sfm_manual_safe_realpath(
            $stagingDirectory
                . '/visual_merge_result.json'
        );

        $result = json_decode(
            (string)file_get_contents($resultDraft),
            true
        );
        if (!is_array($result)) {
            throw new RuntimeException(
                'Visual result JSON is not readable'
            );
        }

        $resultMatrix = sfm_manual_extract_matrix4($result);
        if (
            $resultMatrix === null
            || sfm_manual_visual_transform_hash(
                sfm_manual_visual_normalize_matrix4(
                    $resultMatrix
                )
            ) !== $transformHash
        ) {
            throw new RuntimeException(
                'Visual transform changed during server processing'
            );
        }

        $anchorPoints = (int)$anchor['points'];
        $sourcePoints = (int)$source['points'];
        $mergedPoints = (int)(
            $result['merged_points']
            ?? sfm_manual_ply_vertices($mergedDraft)
        );
        if (
            $mergedPoints
            !== $anchorPoints + $sourcePoints
        ) {
            throw new RuntimeException(
                'Merged point count does not equal '
                . 'Anchor + Moving source'
            );
        }

        $anchorMd5 = md5_file($anchor['ply']);
        $sourceMd5 = md5_file($source['ply']);
        $outputMd5 = md5_file($mergedDraft);

        if (
            isset($result['anchor_md5'])
            && !hash_equals(
                (string)$result['anchor_md5'],
                $anchorMd5
            )
        ) {
            throw new RuntimeException(
                'Anchor PLY fingerprint mismatch'
            );
        }
        if (
            isset($result['source_md5'])
            && !hash_equals(
                (string)$result['source_md5'],
                $sourceMd5
            )
        ) {
            throw new RuntimeException(
                'Moving-source PLY fingerprint mismatch'
            );
        }
        if (
            isset($result['merged_md5'])
            && !hash_equals(
                (string)$result['merged_md5'],
                $outputMd5
            )
        ) {
            throw new RuntimeException(
                'Merged PLY fingerprint mismatch'
            );
        }
        if (
            $outputMd5 === $anchorMd5
            || $outputMd5 === $sourceMd5
        ) {
            throw new RuntimeException(
                'Merged PLY matches one source PLY'
            );
        }

        $incremental = $anchorKind === 'merge';
        $mergeType = $incremental
            ? SFM_MANUAL_VISUAL_INCREMENTAL_MERGE_TYPE
            : SFM_MANUAL_VISUAL_MERGE_TYPE;
        $method = $incremental
            ? SFM_MANUAL_VISUAL_INCREMENTAL_MERGE_METHOD
            : SFM_MANUAL_VISUAL_MERGE_METHOD;

        $fingerprint = sfm_manual_visual_fingerprint(
            $orderId,
            $mergeType,
            $anchor,
            $source,
            $transformHash,
            $anchorMd5,
            $sourceMd5,
            $outputMd5
        );

        if (
            $existing = sfm_manual_find_existing_merge(
                $db,
                $orderId,
                $fingerprint
            )
        ) {
            sfm_manual_visual_cleanup_staging(
                $stagingDirectory
            );
            flock($lock, LOCK_UN);
            fclose($lock);
            return [
                'ok' => true,
                'already_saved' => true,
                'merge_id' => (int)$existing['id'],
                'merge' => $existing,
            ];
        }

        $newLeaf = [
            'db_job_id' => (int)$source['db_job_id'],
            'remote_job_id' => (int)$source['remote_job_id'],
            'model_id' => $source['model_id'],
        ];

        $sourceJobs = $incremental
            ? array_values(
                array_merge(
                    $anchor['leaf_source_jobs'] ?? [],
                    [$newLeaf]
                )
            )
            : [
                [
                    'db_job_id' =>
                        (int)$anchor['db_job_id'],
                    'remote_job_id' =>
                        (int)$anchor['remote_job_id'],
                    'model_id' => $anchor['model_id'],
                ],
                $newLeaf,
            ];

        $sourceJobsJson = json_encode(
            $sourceJobs,
            JSON_UNESCAPED_SLASHES
        );
        if (!is_string($sourceJobsJson)) {
            throw new RuntimeException(
                'Cannot encode visual assembly provenance'
            );
        }

        $message = $incremental
            ? sprintf(
                'manual visual incremental Sim3; '
                . 'parent merge=%d; source DB/remote job=%d/%d; '
                . 'scale=%.9f',
                (int)$anchor['merge_id'],
                (int)$source['db_job_id'],
                (int)$source['remote_job_id'],
                (float)$normalized['scale']
            )
            : sprintf(
                'manual visual Sim3; '
                . 'anchor DB/remote job=%d/%d; '
                . 'source DB/remote job=%d/%d; scale=%.9f',
                (int)$anchor['db_job_id'],
                (int)$anchor['remote_job_id'],
                (int)$source['db_job_id'],
                (int)$source['remote_job_id'],
                (float)$normalized['scale']
            );

        $db->begin_transaction();
        $inTransaction = true;

        $acceptedBase = sfm_manual_safe_dir(
            sfm_manual_output_root()
                . '/accepted_manual_alignments/order_'
                . $orderId
        );
        $placeholderOutput =
            $acceptedBase . '/pending_' . $fingerprint . '.ply';
        $placeholderResult =
            $acceptedBase . '/pending_' . $fingerprint . '.json';

        $statement = $db->prepare(
            'INSERT INTO sfm_generated_model_merges '
            . '(order_id,capture_session_id,created_by_user_id,'
            . 'status,merge_type,source_jobs_json,output_path,'
            . 'result_json_path,total_points,message,idempotency_key) '
            . 'VALUES (?,?,?,\'DONE\',?,?,?,?,?,?,?)'
        );
        if (!$statement) {
            throw new RuntimeException(
                'DB prepare error: ' . $db->error
            );
        }

        $sessionId = (int)$anchor['capture_session_id'];
        $statement->bind_param(
            'iiissssiss',
            $orderId,
            $sessionId,
            $userId,
            $mergeType,
            $sourceJobsJson,
            $placeholderOutput,
            $placeholderResult,
            $mergedPoints,
            $message,
            $fingerprint
        );
        $statement->execute();
        $mergeId = (int)$statement->insert_id;
        $statement->close();

        $acceptedDirectory = sfm_manual_safe_dir(
            $acceptedBase . '/merge_' . $mergeId
        );
        $acceptedOutput =
            $acceptedDirectory
                . '/manual_visual_merged_dense_cloud.ply';
        $acceptedAligned =
            $acceptedDirectory
                . '/source_visual_aligned_to_anchor.ply';
        $acceptedTransform =
            $acceptedDirectory . '/visual_transform.json';
        $acceptedResult =
            $acceptedDirectory . '/merge_result.json';

        sfm_manual_copy_immutable(
            $mergedDraft,
            $acceptedOutput
        );
        sfm_manual_copy_immutable(
            $alignedDraft,
            $acceptedAligned
        );
        sfm_manual_copy_immutable(
            $transformPath,
            $acceptedTransform
        );

        $result['status'] = 'ACCEPTED';
        $result['finalized_at'] = gmdate('c');
        $result['merge_id'] = $mergeId;
        $result['merge_type'] = $mergeType;
        $result['method'] = $method;
        $result['order_id'] = $orderId;
        $result['capture_session_id'] = $sessionId;
        $result['pipeline_run_id'] =
            (int)($anchor['pipeline_run_id']
                ?: $source['pipeline_run_id']);
        $result['anchor_kind'] = $anchorKind;
        $result['anchor_merge_id'] = $incremental
            ? (int)$anchor['merge_id']
            : null;
        $result['anchor_db_job_id'] =
            (int)$anchor['db_job_id'];
        $result['anchor_remote_job_id'] =
            (int)$anchor['remote_job_id'];
        $result['source_db_job_id'] =
            (int)$source['db_job_id'];
        $result['source_remote_job_id'] =
            (int)$source['remote_job_id'];
        $result['source_jobs'] = $sourceJobs;
        $result['leaf_source_jobs'] = $sourceJobs;
        $result['parent_inputs'] = $incremental
            ? [
                [
                    'kind' => 'merge',
                    'merge_id' => (int)$anchor['merge_id'],
                ],
                ['kind' => 'remote'] + $newLeaf,
            ]
            : [
                ['kind' => 'remote'] + $sourceJobs[0],
                ['kind' => 'remote'] + $sourceJobs[1],
            ];
        $result['assembly_frame'] = [
            'kind' => 'merge',
            'merge_id' => $mergeId,
        ];

        $leafTransforms = $incremental
            ? ($anchor['leaf_transforms'] ?? [])
            : [
                $sourceJobs[0] + [
                    'matrix4_to_assembly' =>
                        sfm_manual_identity_matrix4(),
                ],
            ];
        $leafTransforms[] = $newLeaf + [
            'matrix4_to_assembly' =>
                $normalized['matrix4'],
        ];
        $result['leaf_transforms'] = $leafTransforms;
        $result['operation'] = $incremental
            ? 'incremental_visual_add_model'
            : 'base_visual_merge';
        if ($incremental) {
            $result['parent_merge_id'] =
                (int)$anchor['merge_id'];
        }

        $result['confirmed_by_user_id'] =
            $userId > 0 ? $userId : null;
        $result['idempotency_key'] = $fingerprint;
        $result['transform_sha256'] = $transformHash;
        $result['visual_transform_path'] =
            $acceptedTransform;
        $result['aligned_source_path'] =
            $acceptedAligned;
        $result['merged_path'] = $acceptedOutput;
        $result['output_md5'] = $outputMd5;
        $result['anchor_md5'] = $anchorMd5;
        $result['source_md5'] = $sourceMd5;

        sfm_manual_atomic_write_json(
            $acceptedResult,
            $result
        );
        @chmod($acceptedResult, 0444);

        $update = $db->prepare(
            'UPDATE sfm_generated_model_merges '
            . 'SET output_path=?,result_json_path=? '
            . 'WHERE id=?'
        );
        if (!$update) {
            throw new RuntimeException(
                'DB prepare error: ' . $db->error
            );
        }
        $update->bind_param(
            'ssi',
            $acceptedOutput,
            $acceptedResult,
            $mergeId
        );
        $update->execute();
        $update->close();

        $db->commit();
        $inTransaction = false;

        sfm_manual_visual_cleanup_staging(
            $stagingDirectory
        );
        flock($lock, LOCK_UN);
        fclose($lock);

        return [
            'ok' => true,
            'already_saved' => false,
            'merge_id' => $mergeId,
            'output_path' => $acceptedOutput,
            'result_json_path' => $acceptedResult,
            'scale' => $normalized['scale'],
            'merged_points' => $mergedPoints,
        ];
    } catch (mysqli_sql_exception $error) {
        if ($inTransaction) {
            $db->rollback();
        }
        if (
            isset($fingerprint)
            && (int)$error->getCode() === 1062
            && (
                $existing = sfm_manual_find_existing_merge(
                    $db,
                    $orderId,
                    $fingerprint
                )
            )
        ) {
            sfm_manual_visual_cleanup_staging(
                $stagingDirectory
            );
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            return [
                'ok' => true,
                'already_saved' => true,
                'merge_id' => (int)$existing['id'],
                'merge' => $existing,
            ];
        }

        sfm_manual_visual_cleanup_staging(
            $stagingDirectory
        );
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        throw $error;
    } catch (Throwable $error) {
        if ($inTransaction) {
            $db->rollback();
        }
        sfm_manual_visual_cleanup_staging(
            $stagingDirectory
        );
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        throw $error;
    }
}

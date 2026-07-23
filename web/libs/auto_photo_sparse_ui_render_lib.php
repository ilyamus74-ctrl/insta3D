<?php
declare(strict_types=1);

/** Read-only HTML renderer for the already-normalized Auto Photo sparse UI DTO. */
function auto_photo_sparse_ui_render_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function auto_photo_sparse_ui_render_status_badge(mixed $status): string
{
    $status = strtoupper(trim((string) $status));
    $class = match ($status) {
        'DONE', 'UPLOADED', 'PROCESSED' => 'bg-success',
        'ERROR', 'FAILED' => 'bg-danger',
        'CANCELLED' => 'bg-warning text-dark',
        'QUEUED', 'RUNNING', 'PLANNING', 'RUNNING_CHUNKS', 'MERGING' => 'bg-info text-dark',
        default => 'bg-secondary',
    };
    return '<span class="badge ' . $class . '">' . auto_photo_sparse_ui_render_escape($status) . '</span>';
}

function auto_photo_sparse_ui_render_progress(mixed $value): string
{
    $progress = max(0, min(100, (int) $value));
    return '<div class="progress" style="height: 0.5rem;"><div class="progress-bar" role="progressbar" style="width: ' . $progress . '%;" aria-valuenow="' . $progress . '" aria-valuemin="0" aria-valuemax="100">' . $progress . '%</div></div>';
}

function auto_photo_sparse_ui_render_value(mixed $value): string
{
    return (string) $value === '' ? '—' : auto_photo_sparse_ui_render_escape($value);
}

function auto_photo_sparse_ui_render_message(mixed $message): string
{
    if ((string) $message === '') {
        return '';
    }
    return '<details class="mt-2"><summary>Сообщение</summary><pre class="text-break mb-0">'
        . auto_photo_sparse_ui_render_escape($message) . '</pre></details>';
}

function auto_photo_sparse_ui_render_nav(array $dto): string
{
    if (($dto['visible'] ?? false) !== true) {
        return '';
    }
    return '<li class="nav-item"><button class="nav-link" id="simple-photo-sfm-tab" data-bs-toggle="pill" data-bs-target="#simple-photo-sfm" type="button" role="tab" aria-controls="simple-photo-sfm" aria-selected="false">Фото 3D</button></li>';
}

function auto_photo_sparse_ui_render_export(mixed $export): string
{
    if (!is_array($export)) {
        return 'Не создан';
    }
    $html = auto_photo_sparse_ui_render_status_badge($export['status'] ?? '') . '<br>'
        . auto_photo_sparse_ui_render_progress($export['progress_percent'] ?? 0)
        . '<div class="small mt-1">DB job ID: ' . (int) ($export['db_job_id'] ?? 0) . '</div>'
        . auto_photo_sparse_ui_render_message($export['message'] ?? '');
    $url = (string) ($export['download_url'] ?? '');
    if ($url !== '') {
        $html .= '<a class="btn btn-sm btn-outline-primary mt-2" href="'
            . auto_photo_sparse_ui_render_escape($url) . '">Скачать PLY</a>';
    }
    return $html;
}

function auto_photo_sparse_ui_render_dense(mixed $dense): string
{
    if (!is_array($dense)) return 'Не создан';
    $html = auto_photo_sparse_ui_render_status_badge($dense['status'] ?? '')
        . auto_photo_sparse_ui_render_progress($dense['progress_percent'] ?? 0)
        . '<div class="small mt-1">DB job ID: ' . (int)($dense['db_job_id'] ?? 0)
        . '<br>Remote job ID: ' . (int)($dense['remote_job_id'] ?? 0)
        . '<br>Sparse remote ID: ' . (int)($dense['source_sparse_remote_job_id'] ?? 0)
        . '<br>Model ID: ' . (int)($dense['model_id'] ?? 0)
        . '<br>Dense points: ' . number_format((int)($dense['dense_points'] ?? 0), 0, '.', ' ')
        . '<br>Размер: ' . (int)($dense['file_size_bytes'] ?? 0) . ' B</div>'
        . auto_photo_sparse_ui_render_message($dense['message'] ?? '');
    if (($url = (string)($dense['download_url'] ?? '')) !== '') $html .= '<a class="btn btn-sm btn-outline-primary mt-2" href="' . auto_photo_sparse_ui_render_escape($url) . '">Скачать merged_fused.ply</a>';
    return $html;
}

function auto_photo_sparse_ui_render_action_form(
    array $actionContext,
    string $action,
    array $fields,
    string $label,
    string $buttonClass = 'btn-outline-primary',
    string $confirmation = ''
): string {
    $postUrl = (string) ($actionContext['post_url'] ?? '');
    $csrfName = (string) ($actionContext['csrf_name'] ?? '');
    $csrfValue = (string) ($actionContext['csrf_value'] ?? '');
    if ($postUrl === '' || $csrfName === '' || $csrfValue === '') {
        return '';
    }
    foreach ($fields as $name => $value) {
        if (($name === 'sparse_db_job_id' || $name === 'capture_bundle_id')
            && (!is_int($value) || $value <= 0)) {
            return '';
        }
        if ($name === 'model_id' && (!is_int($value) || $value < 0)) {
            return '';
        }
    }
    $html = '<form method="post" action="' . auto_photo_sparse_ui_render_escape($postUrl) . '"';
    if ($confirmation !== '') {
        $html .= ' onsubmit="return confirm(\'' . auto_photo_sparse_ui_render_escape($confirmation) . '\')"';
    }
    $html .= '><input type="hidden" name="' . auto_photo_sparse_ui_render_escape($csrfName)
        . '" value="' . auto_photo_sparse_ui_render_escape($csrfValue) . '">'
        . '<input type="hidden" name="action" value="' . auto_photo_sparse_ui_render_escape($action) . '">';
    foreach ($fields as $name => $value) {
        $valid = ($name === 'sparse_db_job_id' || $name === 'capture_bundle_id')
            ? is_int($value) && $value > 0
            : ($name === 'model_id' && is_int($value) && $value >= 0);
        if (!$valid) {
            continue;
        }
        $html .= '<input type="hidden" name="' . auto_photo_sparse_ui_render_escape($name)
            . '" value="' . $value . '">';
    }
    return $html . '<button type="submit" class="btn btn-sm ' . auto_photo_sparse_ui_render_escape($buttonClass)
        . '">' . auto_photo_sparse_ui_render_escape($label) . '</button></form>';
}

function auto_photo_sparse_ui_render_pane(array $dto, array $actionContext = []): string
{
    if (($dto['visible'] ?? false) !== true) {
        return '';
    }
    $bundle = is_array($dto['bundle'] ?? null) ? $dto['bundle'] : [];
    $html = '<div class="tab-pane fade" id="simple-photo-sfm" role="tabpanel" aria-labelledby="simple-photo-sfm-tab">';
    $html .= '<div class="card mb-3"><div class="card-body"><h5 class="card-title">Фото 3D</h5><div class="row g-3">'
        . '<div class="col-md"><div class="text-muted small">Пакет</div><div>' . (int) ($bundle['id'] ?? 0) . '</div></div>'
        . '<div class="col-md"><div class="text-muted small">Сессия</div><div>' . (int) ($bundle['capture_session_id'] ?? 0) . '</div></div>'
        . '<div class="col-md"><div class="text-muted small">Фотографии</div><div>' . (($bundle['photos_count_known'] ?? false) === true ? (int) ($bundle['photos_count'] ?? 0) : '—') . '</div></div>'
        . '<div class="col-md"><div class="text-muted small">Статус</div><div>' . auto_photo_sparse_ui_render_status_badge($bundle['status'] ?? '') . '</div></div>'
        . '<div class="col-12"><div class="text-muted small">UUID</div><div class="text-break">' . auto_photo_sparse_ui_render_value($bundle['app_bundle_uuid'] ?? '') . '</div></div>'
        . '</div></div></div>';
    if (($dto['active_jobs'] ?? false) === true) {
        $html .= '<div class="alert alert-info">Обработка Auto Photo выполняется</div>';
    }
    $prepare = $dto['prepare'] ?? null;
    $html .= '<div class="card mb-3"><div class="card-body"><h5 class="card-title">Подготовка</h5>';
    $showPrepareForm = ($bundle['can_prepare'] ?? false) === true;
    $prepareForm = $showPrepareForm
        ? auto_photo_sparse_ui_render_action_form(
            $actionContext,
            'auto_photo_prepare_bundle',
            ['capture_bundle_id' => (int) ($bundle['id'] ?? 0)],
            'Подготовить и запустить обработку',
            'btn-outline-primary',
            'Проиндексировать пакет, подготовить фотографии и запустить обработку?'
        )
        : '';
    if (!is_array($prepare)) {
        $html .= $prepareForm;
        $html .= 'Подготовка ещё не запускалась';
    } else {
        $html .= '<div class="row g-3"><div class="col-md">DB job ID<br><strong>' . (int) ($prepare['db_job_id'] ?? 0) . '</strong></div>'
            . '<div class="col-md">Remote job ID<br><strong>' . (int) ($prepare['remote_job_id'] ?? 0) . '</strong></div>'
            . '<div class="col-md">Статус<br>' . auto_photo_sparse_ui_render_status_badge($prepare['status'] ?? '') . '</div>'
            . '<div class="col-md">Progress' . auto_photo_sparse_ui_render_progress($prepare['progress_percent'] ?? 0) . '</div></div>'
            . auto_photo_sparse_ui_render_message($prepare['message'] ?? '');
        $html .= $prepareForm;
    }
    $html .= '</div></div>';
    foreach ((is_array($dto['runs'] ?? null) ? $dto['runs'] : []) as $run) {
        if (!is_array($run)) { continue; }
        $html .= '<div class="card mb-3"><div class="card-header">Sparse job #' . (int) ($run['sparse_db_job_id'] ?? 0)
            . ' <span class="text-muted small">remote job ID ' . (int) ($run['sparse_remote_job_id'] ?? 0) . '</span> '
            . auto_photo_sparse_ui_render_status_badge($run['status'] ?? '');
        if (($run['recommended_run'] ?? false) === true) { $html .= ' <span class="badge bg-primary">Рекомендованный запуск</span>'; }
        $html .= '</div><div class="card-body"><div class="row g-2 small"><div class="col-md">Progress' . auto_photo_sparse_ui_render_progress($run['progress_percent'] ?? 0) . '</div>'
            . '<div class="col-md">Matcher: ' . auto_photo_sparse_ui_render_value($run['matcher'] ?? '') . '</div>'
            . '<div class="col-md">Retry mode: ' . auto_photo_sparse_ui_render_value($run['retry_mode'] ?? '') . '</div>'
            . '<div class="col-md">Моделей: ' . (int) ($run['models_count'] ?? 0) . '<br>Входных фото: ' . (int) ($run['input_images'] ?? 0) . '</div></div>'
            . auto_photo_sparse_ui_render_message($run['message'] ?? '');
        $sparseDbJobId = $run['sparse_db_job_id'] ?? null;
        if (($run['can_retry_exhaustive'] ?? false) === true
            && is_int($sparseDbJobId) && $sparseDbJobId > 0) {
            $html .= '<div class="mt-2">' . auto_photo_sparse_ui_render_action_form(
                $actionContext,
                'auto_photo_sparse_retry_exhaustive',
                ['sparse_db_job_id' => $sparseDbJobId],
                'Повторить с exhaustive matcher',
                'btn-outline-warning',
                'Создать отдельный exhaustive sparse retry? Исходный sparse job изменён не будет.'
            ) . '</div>';
        }
        if (($run['merge_warning'] ?? false) === true) { $html .= '<div class="alert alert-warning mt-2 mb-0">Компоненты имеют недостаточно общих изображений для надёжного объединения</div>'; }
        $models = is_array($run['models'] ?? null) ? $run['models'] : [];
        if ($models === []) { $html .= '<div class="text-muted mt-3">Модели для этого запуска отсутствуют</div>'; }
        else { $html .= '<div class="table-responsive mt-3"><table class="table table-sm align-middle"><thead><tr><th>Модель</th><th>Статус</th><th>Зарегистрировано</th><th>Точки</th><th>Первое изображение</th><th>Последнее изображение</th><th>Диапазоны кадров</th><th>Общие изображения</th><th>Экспорт</th><th>Действия</th></tr></thead><tbody>';
            foreach ($models as $model) { if (!is_array($model)) { continue; }
                $registered = (int) ($model['registered_images'] ?? 0);
                $input = (int) ($run['input_images'] ?? 0);
                $registeredText = $input > 0 ? $registered . ' / ' . $input . ' (' . number_format((float) ($model['registered_percent'] ?? 0), 1, '.', '') . '%)' : (string) $registered;
                $status = ''; if (($model['selected'] ?? false) === true) { $status .= '<span class="badge bg-info text-dark">Выбрана</span> '; } if (($model['recommended'] ?? false) === true) { $status .= '<span class="badge bg-primary">Рекомендована</span>'; }
                $modelId = $model['model_id'] ?? null;
                $actions = [];
                if (is_int($sparseDbJobId) && $sparseDbJobId > 0 && is_int($modelId) && $modelId >= 0) {
                    if (($model['can_select'] ?? false) === true) {
                        $actions[] = auto_photo_sparse_ui_render_action_form($actionContext, 'auto_photo_sparse_select_model', ['sparse_db_job_id' => $sparseDbJobId, 'model_id' => $modelId], 'Выбрать модель');
                    }
                    if (($model['can_export'] ?? false) === true) {
                        $actions[] = auto_photo_sparse_ui_render_action_form($actionContext, 'auto_photo_sparse_export_ply', ['sparse_db_job_id' => $sparseDbJobId, 'model_id' => $modelId], 'Экспортировать PLY');
                    }
                    if (($model['can_dense_preview'] ?? false) === true) {
                        $actions[] = auto_photo_sparse_ui_render_action_form($actionContext, 'auto_photo_sparse_build_dense_preview', ['sparse_db_job_id' => $sparseDbJobId, 'model_id' => $modelId], 'Построить Dense Preview');
                    }
                }
                $actions = array_filter($actions, static fn(string $form): bool => $form !== '');
                $actionsHtml = $actions === [] ? '—' : '<div class="d-flex flex-column gap-1">' . implode('', $actions) . '</div>';
                $html .= '<tr><td>' . (int) ($model['model_id'] ?? 0) . '</td><td>' . ($status !== '' ? $status : '—') . '</td><td>' . $registeredText . '</td><td>' . number_format((int) ($model['points3D_count'] ?? 0), 0, '.', ' ') . '</td><td>' . auto_photo_sparse_ui_render_value($model['first_image'] ?? '') . '</td><td>' . auto_photo_sparse_ui_render_value($model['last_image'] ?? '') . '</td><td>' . auto_photo_sparse_ui_render_value($model['frame_ranges_label'] ?? '') . '</td><td>' . auto_photo_sparse_ui_render_value($model['shared_images_label'] ?? '') . '</td><td>' . auto_photo_sparse_ui_render_export($model['export'] ?? null) . '<hr class="my-2">' . auto_photo_sparse_ui_render_dense($model['dense'] ?? null) . '</td><td>' . $actionsHtml . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '</div></div>';
    }
    return $html . '</div>';
}

function auto_photo_sparse_ui_render(array $dto, array $actionContext = []): array
{
    return [
        'nav' => auto_photo_sparse_ui_render_nav($dto),
        'pane' => auto_photo_sparse_ui_render_pane($dto, $actionContext),
    ];
}

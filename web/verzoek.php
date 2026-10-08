<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$id = isset($_REQUEST['id']) && ctype_digit((string) $_REQUEST['id']) ? (int) $_REQUEST['id'] : null;
$request = $id !== null ? thoth_get_request($id) : null;
if ($id !== null && ($request === null || !thoth_can_view($request, $thothUser))) {
    http_response_code(404);
    thoth_header('Niet gevonden');
    echo '<p>Dit verzoek bestaat niet of je mag het niet zien.</p>';
    thoth_footer();
    exit;
}
$type = $request['type'] ?? (string) ($_GET['type'] ?? '');
if (!thoth_type_exists($type)) {
    http_response_code(400);
    thoth_header('Onbekend type');
    echo '<p>Onbekend aanvraagtype.</p>';
    thoth_footer();
    exit;
}

$companies = thoth_companies();
$fieldErrors = [];
$configError = null;
try {
    $config = thoth_load_config($type);
} catch (ThothConfigException $e) {
    $config = null;
    $configError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config !== null) {
    thoth_require_csrf();
    $action = (string) ($_POST['actie'] ?? '');
    try {
        if ($action === 'indienen') {
            $company = thoth_company_by_key((string) ($_POST['bedrijf'] ?? ''), $companies);
            $request = thoth_save_draft($id, $type, $thothUser, (array) ($_POST['v'] ?? []), (array) ($_POST['l'] ?? []), $company['name'] ?? '', $company['environment'] ?? '');
            $id = $request['id'];
            if ($company !== null) {
                thoth_set_pref($thothUser, $company['name'], $company['environment']);
            }
            $fieldErrors = thoth_submit($id, $thothUser);
            if ($fieldErrors === []) {
                thoth_flash('Verzoek #' . $id . ' is ingediend.');
                thoth_redirect('index.php');
            }
            $request = thoth_get_request($id);
        } elseif ($action === 'goedkeuren' && $id !== null) {
            $result = thoth_approve($id, $thothUser);
            if ($result['ok']) {
                thoth_flash('Goedgekeurd en aangemaakt in BC met nummer ' . $result['number'] . '.');
                thoth_redirect('goedkeuren.php');
            }
            thoth_flash($result['error'] . "\nHet verzoek blijft op Ingediend.", 'error');
            thoth_redirect('verzoek.php?id=' . $id);
        } elseif ($action === 'afwijzen' && $id !== null) {
            thoth_reject($id, $thothUser, (string) ($_POST['reden'] ?? ''));
            thoth_flash('Verzoek #' . $id . ' is afgewezen.');
            thoth_redirect('goedkeuren.php');
        }
    } catch (ThothActionException $e) {
        thoth_flash($e->getMessage(), 'error');
        thoth_redirect($id !== null ? 'verzoek.php?id=' . $id : 'index.php');
    }
}

$editable = $request === null || thoth_can_edit($request, $thothUser);
$pref = thoth_get_pref($thothUser);
$companyKey = $request !== null && $request['company'] !== ''
    ? thoth_company_key($request['environment'], $request['company'])
    : thoth_company_key($pref['environment'], $pref['company']);
$currentCompany = thoth_company_by_key($companyKey, $companies);
$context = ['company' => $currentCompany['name'] ?? ($request['company'] ?? ''), 'environment' => $currentCompany['environment'] ?? ($request['environment'] ?? ''), 'moment' => 'opslaan'];
$values = $request['data'] ?? [];
$labels = $request['labels'] ?? [];

thoth_header(($request ? 'Verzoek #' . $request['id'] : 'Nieuwe ' . strtolower(thoth_type_label($type))));
?>
<h1><?= h(thoth_type_label($type)) ?> <?= $request ? '#' . (int) $request['id'] . ' ' . thoth_status_pill($request['status']) : 'aanvragen' ?></h1>

<?php if ($configError !== null): ?>
  <div class="flash flash-error"><strong>Config-fout</strong><br><?= nl2br(h($configError)) ?></div>
<?php else: ?>
  <?php if ($config['voorbeeld']): ?><div class="flash flash-warn">Voorbeeldconfig: <?= h($config['voorbeeldNotitie']) ?></div><?php endif; ?>
  <?php if ($request && $request['status'] === THOTH_STATUS_REJECTED): ?>
    <div class="flash flash-warn">Afgewezen door <?= h($request['decided_by']) ?>. Reden: <?= $request['reject_reason'] !== null ? h($request['reject_reason']) : '<em>geen reden opgegeven</em>' ?>. Pas het verzoek aan en dien het opnieuw in.</div>
  <?php endif; ?>
  <?php if ($request && $request['bc_error'] && thoth_is_approver($thothUser)): ?>
    <div class="flash flash-error"><strong>Laatste goedkeuring mislukt:</strong> <?= h($request['bc_error']) ?></div>
  <?php endif; ?>
  <?php if ($request && $request['bc_number']): ?>
    <div class="flash flash-ok">Aangemaakt in BC als <strong><?= h($request['bc_number']) ?></strong>.</div>
  <?php endif; ?>
  <?php if ($fieldErrors !== []): ?>
    <div class="flash flash-error">Nog niet ingediend:<ul><?php foreach ($fieldErrors as $msg): ?><li><?= h($msg) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="card form" id="verzoek-form" data-type="<?= h($type) ?>" data-editable="<?= $editable ? '1' : '0' ?>" novalidate>
    <?= thoth_csrf_field() ?>
    <input type="hidden" name="id" value="<?= $request ? (int) $request['id'] : '' ?>">
    <input type="hidden" name="type" value="<?= h($type) ?>">
    <div class="field">
      <label for="bedrijf">Bedrijf <span class="req">*</span></label>
      <select id="bedrijf" name="bedrijf" <?= $editable ? '' : 'disabled' ?> required>
        <option value="">— kies een bedrijf —</option>
        <?php foreach ($companies as $c): ?>
          <option value="<?= h($c['key']) ?>"<?= $c['key'] === $companyKey ? ' selected' : '' ?>><?= h($c['label']) ?></option>
        <?php endforeach; ?>
        <?php if ($currentCompany === null && ($request['company'] ?? '') !== ''): ?>
          <option value="<?= h($companyKey) ?>" selected><?= h($request['company'] . ' (' . $request['environment'] . ')') ?></option>
        <?php endif; ?>
      </select>
    </div>
    <?php foreach ($config['formFields'] as $field):
        $key = $field['key'];
        $value = (string) ($values[$key] ?? '');
        $inputId = 'f_' . $key;
        $name = 'v[' . $key . ']';
        $dis = $editable ? '' : ' disabled';
        $req = $field['verplicht'] ? ' data-verplicht="1"' : '';
        ?>
      <div class="field<?= isset($fieldErrors[$key]) ? ' has-error' : '' ?>">
        <label for="<?= h($inputId) ?>"><?= h($field['name']) ?><?= $field['verplicht'] ? ' <span class="req">*</span>' : '' ?></label>
        <?php switch ($field['invoerType']):
            case 'dropdown':
                $opts = [];
                $optErr = null;
                try {
                    $opts = thoth_field_options($field, $context);
                } catch (Throwable $e) {
                    $optErr = $e->getMessage();
                } ?>
          <select id="<?= h($inputId) ?>" name="<?= h($name) ?>"<?= $req . $dis ?>>
            <option value=""><?= h($field['placeholder'] ?: '— kies —') ?></option>
            <?php $found = false; foreach ($opts as $o): $sel = thoth_same_value($o['waarde'], $value) || thoth_same_value($o['label'], $value); $found = $found || $sel; ?>
              <option value="<?= h($o['waarde']) ?>"<?= $sel ? ' selected' : '' ?>><?= h($o['label']) ?></option>
            <?php endforeach; ?>
            <?php if (!$found && $value !== ''): ?><option value="<?= h($value) ?>" selected><?= h($value) ?> (onbekend)</option><?php endif; ?>
          </select>
          <?php if ($optErr !== null): ?><div class="error-inline">Opties konden niet worden geladen: <?= h($optErr) ?></div><?php endif; ?>
        <?php break;
            case 'combobox':
            case 'lookup':
                $strict = $field['invoerType'] === 'lookup';
                $label = (string) ($labels[$key] ?? $value); ?>
          <div class="combo" data-combo data-strikt="<?= $strict ? '1' : '0' ?>" data-veld="<?= h($key) ?>">
            <input type="text" id="<?= h($inputId) ?>" class="combo-input" autocomplete="off" placeholder="<?= h($field['placeholder']) ?>"
              value="<?= h($strict ? $label : $value) ?>"<?= $strict ? '' : ' name="' . h($name) . '"' ?><?= $req . $dis ?>>
            <?php if ($strict): ?>
              <input type="hidden" class="combo-value" name="<?= h($name) ?>" value="<?= h($value) ?>">
              <input type="hidden" class="combo-label" name="l[<?= h($key) ?>]" value="<?= h($label) ?>">
            <?php endif; ?>
            <ul class="combo-list" role="listbox" hidden></ul>
            <?php if ($strict): ?><div class="hint">Typ om te zoeken en kies een bestaande waarde uit de lijst.</div><?php endif; ?>
          </div>
        <?php break;
            default:
                $htmlType = ['date' => 'date', 'time' => 'time', 'datetime' => 'datetime-local'][$field['invoerType']] ?? 'text'; ?>
          <input type="<?= $htmlType ?>" id="<?= h($inputId) ?>" name="<?= h($name) ?>" value="<?= h($value) ?>" placeholder="<?= h($field['placeholder']) ?>"
            <?= $field['invoerType'] === 'nummer' ? 'inputmode="decimal"' : '' ?><?= $req . $dis ?>>
        <?php endswitch; ?>
        <?php if (isset($fieldErrors[$key])): ?><div class="error-inline"><?= h($fieldErrors[$key]) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($editable): ?>
      <div class="form-actions">
        <span class="save-status" id="save-status" aria-live="polite"><?= $request ? 'Opgeslagen ' . h(substr((string) $request['updated_at'], 11, 8)) : '' ?></span>
        <button type="submit" name="actie" value="indienen" id="btn-indienen" class="btn">Inzenden</button>
      </div>
    <?php endif; ?>
  </form>

  <?php if ($request && $request['status'] === THOTH_STATUS_SUBMITTED && thoth_is_approver($thothUser)): ?>
    <section class="card approve-box">
      <h2>Beoordelen</h2>
      <p class="muted">Aanvrager: <?= h($request['owner']) ?> · Bedrijf: <?= h($request['company']) ?> (<?= h($request['environment']) ?>) · BC-tabel: <?= h($config['bc-tabel']) ?></p>
      <form method="post" class="inline">
        <?= thoth_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
        <button type="submit" name="actie" value="goedkeuren" class="btn btn-ok">Goedkeuren en aanmaken in BC</button>
      </form>
      <form method="post" class="reject-form">
        <?= thoth_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
        <label for="reden">Reden van afwijzen (optioneel)</label>
        <textarea id="reden" name="reden" rows="2" maxlength="2000"></textarea>
        <button type="submit" name="actie" value="afwijzen" class="btn btn-danger">Afwijzen</button>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($request): ?>
    <section class="card">
      <h2>Historie</h2>
      <ul class="list history">
      <?php foreach (thoth_history($request['id']) as $hist): ?>
        <li><span class="muted"><?= h($hist['at']) ?></span> · <?= h($hist['actor']) ?>:
          <?= $hist['from_status'] ? h($hist['from_status']) . ' → ' : '' ?><strong><?= h($hist['to_status']) ?></strong>
          <?= $hist['reason'] ? '<div class="reason">' . h($hist['reason']) . '</div>' : '' ?></li>
      <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
<?php endif; ?>
<?php thoth_footer();

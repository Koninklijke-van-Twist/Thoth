<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    thoth_require_csrf();
    if (($_POST['actie'] ?? '') === 'bedrijf') {
        $company = thoth_company_by_key((string) ($_POST['bedrijf'] ?? ''), thoth_companies());
        if ($company !== null) {
            thoth_set_pref($thothUser, $company['name'], $company['environment']);
            thoth_flash('Bedrijf gekozen: ' . $company['label']);
        }
    }
    thoth_redirect('index.php');
}

$companies = thoth_companies();
$pref = thoth_get_pref($thothUser);
$prefKey = thoth_company_key($pref['environment'], $pref['company']);
$overview = thoth_user_overview($thothUser);

thoth_header('Mijn verzoeken', 'overzicht');
?>
<h1>Mijn verzoeken</h1>

<section class="card">
  <form method="post" class="row-form">
    <?= thoth_csrf_field() ?>
    <input type="hidden" name="actie" value="bedrijf">
    <label for="bedrijf">Bedrijf</label>
    <select id="bedrijf" name="bedrijf" data-autosubmit>
      <option value="">— kies een bedrijf —</option>
      <?php foreach ($companies as $c): ?>
        <option value="<?= h($c['key']) ?>"<?= $c['key'] === $prefKey ? ' selected' : '' ?>><?= h($c['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit">Kiezen</button></noscript>
  </form>
  <?php if ($companies === []): ?><p class="muted">De bedrijvenlijst kon niet uit BC/Mímir worden opgehaald.</p><?php endif; ?>
  <div class="actions">
    <?php foreach (THOTH_TYPES as $type => $meta): ?>
      <a class="btn" href="verzoek.php?type=<?= h($type) ?>">Nieuwe <?= h(strtolower($meta['label'])) ?> aanvragen</a>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($overview['afgewezen'] !== []): ?>
<section class="card card-warn">
  <h2>Afgewezen – graag aanpassen</h2>
  <ul class="list">
  <?php foreach ($overview['afgewezen'] as $r): ?>
    <li>
      <a href="verzoek.php?id=<?= (int) $r['id'] ?>"><strong>#<?= (int) $r['id'] ?> <?= h(thoth_type_label($r['type'])) ?>: <?= h(thoth_request_title($r)) ?></strong></a>
      <?= thoth_status_pill($r['status']) ?>
      <div class="muted">Afgewezen door <?= h($r['decided_by']) ?> op <?= h($r['decided_at']) ?></div>
      <div class="reason">Reden: <?= $r['reject_reason'] !== null ? h($r['reject_reason']) : '<em>geen reden opgegeven</em>' ?></div>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card">
  <h2>Overige verzoeken</h2>
  <?php if ($overview['overig'] === []): ?>
    <p class="muted">Nog geen verzoeken.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Type</th><th>Omschrijving</th><th>Bedrijf</th><th>Status</th><th>BC-nummer</th><th>Bijgewerkt</th></tr></thead>
    <tbody>
    <?php foreach ($overview['overig'] as $r): ?>
      <tr>
        <td><a href="verzoek.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
        <td><?= h(thoth_type_label($r['type'])) ?></td>
        <td><a href="verzoek.php?id=<?= (int) $r['id'] ?>"><?= h(thoth_request_title($r)) ?></a></td>
        <td><?= h($r['company']) ?></td>
        <td><?= thoth_status_pill($r['status']) ?></td>
        <td><?= h($r['bc_number'] ?? '') ?></td>
        <td><?= h($r['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php thoth_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!thoth_is_approver($thothUser)) {
    http_response_code(403);
    thoth_header('Geen toegang');
    echo '<p>Je staat niet in de lijst met goedkeurders.</p>';
    thoth_footer();
    exit;
}

$submitted = thoth_list_requests('status = ?', [THOTH_STATUS_SUBMITTED], 'submitted_at ASC');
$recent = thoth_list_requests('status IN (?, ?)', [THOTH_STATUS_APPROVED, THOTH_STATUS_REJECTED], 'decided_at DESC LIMIT 25');

thoth_header('Goedkeuren', 'goedkeuren');
?>
<h1>Goedkeuren</h1>
<section class="card">
  <h2>Ingediend (<?= count($submitted) ?>)</h2>
  <?php if ($submitted === []): ?><p class="muted">Niets te beoordelen.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Type</th><th>Omschrijving</th><th>Bedrijf</th><th>Aanvrager</th><th>Ingediend</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($submitted as $r): ?>
      <tr>
        <td>#<?= (int) $r['id'] ?></td>
        <td><?= h(thoth_type_label($r['type'])) ?></td>
        <td><?= h(thoth_request_title($r)) ?><?php if ($r['bc_error']): ?><div class="error-inline">Laatste poging mislukt</div><?php endif; ?></td>
        <td><?= h($r['company']) ?></td>
        <td><?= h($r['owner']) ?></td>
        <td><?= h($r['submitted_at']) ?></td>
        <td><a class="btn btn-small" href="verzoek.php?id=<?= (int) $r['id'] ?>">Beoordelen</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Recent beslist</h2>
  <?php if ($recent === []): ?><p class="muted">Nog niets beslist.</p><?php else: ?>
  <ul class="list">
  <?php foreach ($recent as $r): ?>
    <li><a href="verzoek.php?id=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?> <?= h(thoth_type_label($r['type'])) ?>: <?= h(thoth_request_title($r)) ?></a>
      <?= thoth_status_pill($r['status']) ?> <?= h($r['bc_number'] ?? '') ?> <span class="muted"><?= h($r['decided_by']) ?>, <?= h($r['decided_at']) ?></span></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php thoth_footer();

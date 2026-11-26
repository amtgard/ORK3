<?php
/**
 * Memory Wall tab body on a Legend's profile: one column, newest first.
 * Expects $Player, $Memories, $MemoryCount, $RemovedMemories, $LoggedIn, $CanManageLegend, $ViewerPersona, $LegendMaxMemoryChars.
 * Posting and removing are wired in revised.js and reload the page. Show more
 * clones the row in the <template> below, so a memory has one markup whichever
 * side builds it: here escaped, there as text. white-space: pre-line keeps its line breaks.
 */
$_lgMemories = is_array($Memories ?? null) ? $Memories : [];
$_lgRemoved  = !empty($CanManageLegend) && is_array($RemovedMemories ?? null) ? $RemovedMemories : [];
$_lgPersona  = htmlspecialchars($Player['Persona'] ?? '', ENT_QUOTES);
$_lgManage   = !empty($CanManageLegend);

/** "Remembered by Persona (K:P) · date", from the shape Legend::memories() returns. */
$_lgByline = function (array $m): void {
	$author = htmlspecialchars((string)($m['AuthorPersona'] ?? ''), ENT_QUOTES);
	$home   = (string)($m['AuthorHome'] ?? '');
	?><div class="pn-lg-by">Remembered by
		<?php if ($author !== ''): ?><a class="pn-lg-author" href="<?= UIR ?>Player/profile/<?= (int)$m['AuthorId'] ?>"><b><?= $author ?></b></a><?php else: ?><b>a community member</b><?php endif; ?><span class="pn-lg-home"><?= $home !== '' ? ' (' . htmlspecialchars($home, ENT_QUOTES) . ')' : '' ?></span>
		&middot; <span class="pn-lg-date"><?= htmlspecialchars((string)($m['CreatedOn'] ?? '')) ?></span>
	</div><?php
};

/** One memory on the wall, with the officer controls for those who may use them. */
$_lgRow = function (array $m) use ($_lgManage, $_lgByline): void {
	?><div class="pn-lg-memory" data-id="<?= (int)$m['MemoryId'] ?>">
		<?php if ($_lgManage): ?>
		<span class="pn-lg-remove" data-id="<?= (int)$m['MemoryId'] ?>" data-author="<?= (int)$m['AuthorId'] ?>" data-count="<?= (int)($m['AuthorMemoryCount'] ?? 0) ?>">
			<span class="pn-lg-remove-links">
				<button type="button" class="pn-lg-remove-link">Remove</button>
				<button type="button" class="pn-lg-remove-all-link">Remove all by this author</button>
			</span>
			<span class="pn-lg-remove-confirm" style="display:none">
				<span class="pn-lg-remove-message"></span>
				<input type="text" class="pn-lg-remove-reason" maxlength="255" placeholder="Reason (required)" aria-label="Reason for removal">
				<button type="button" class="pn-lg-remove-yes">Yes</button>
				<button type="button" class="pn-lg-remove-no">No</button>
			</span>
			<span class="pn-lg-remove-error" role="alert" style="display:none"></span>
		</span>
		<?php endif; ?>
		<p><?= htmlspecialchars((string)$m['Body'], ENT_QUOTES) ?></p>
		<?php $_lgByline($m); ?>
	</div><?php
};
?>
<div class="pn-lg-top">
	<p>Newest memories first.</p>
	<?php if (!empty($LoggedIn)): ?>
	<button type="button" class="pn-lg-btn" id="pn-lg-share-btn" aria-expanded="false" aria-controls="pn-lg-form">Share a Memory</button>
	<?php else: ?>
	<a class="pn-lg-login" href="<?= UIR ?>Login/login/Player/profile/<?= (int)($Player['MundaneId'] ?? 0) ?>">Log in to share a memory</a>
	<?php endif; ?>
</div>

<?php if (!empty($LoggedIn)): ?>
<div class="pn-lg-form" id="pn-lg-form" style="display:none">
	<h4>Share a memory of <?= $_lgPersona ?></h4>
	<p>Please remain respectful. This wall honors a member of our community and is read by friends and family. Inappropriate content is subject to removal.</p>
	<div class="pn-form-error" id="pn-lg-error" role="alert"></div>
	<textarea id="pn-lg-body" rows="4" maxlength="<?= (int)$LegendMaxMemoryChars ?>" placeholder="Your memory&hellip;" aria-label="Your memory"></textarea>
	<div class="pn-char-count" id="pn-lg-count" aria-live="polite"><?= (int)$LegendMaxMemoryChars ?> characters remaining</div>
	<div class="pn-lg-foot">
		<span>Remembered by <b><?= htmlspecialchars((string)($ViewerPersona ?? ''), ENT_QUOTES) ?></b></span>
		<button type="button" class="pn-lg-btn" id="pn-lg-post">Post Memory</button>
	</div>
</div>
<?php endif; ?>

<?php if (empty($_lgMemories)): ?>
<div class="pn-empty">No memories have been shared yet.</div>
<?php else: ?>
<div class="pn-lg-list" id="pn-lg-list">
	<?php foreach ($_lgMemories as $_lgM) { $_lgRow($_lgM); } ?>
</div>
<?php if ((int)($MemoryCount ?? 0) > count($_lgMemories)): ?>
<template id="pn-lg-row"><?php $_lgRow(['MemoryId' => 0, 'AuthorId' => 0, 'AuthorPersona' => 'x', 'Body' => '']); ?></template>
<div class="pn-lg-more-wrap">
	<div class="pn-form-error" id="pn-lg-more-error" role="alert"></div>
	<button type="button" class="pn-lg-btn" id="pn-lg-more">Show more memories</button>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($_lgRemoved)): ?>
<details class="pn-lg-removed">
	<summary>Removed memories (<?= count($_lgRemoved) ?>)</summary>
	<div class="pn-lg-list">
		<?php foreach ($_lgRemoved as $_lgM): ?>
		<?php $_lgBy = htmlspecialchars((string)($_lgM['RemovedBy'] ?? ''), ENT_QUOTES); ?>
		<div class="pn-lg-memory">
			<p><?= htmlspecialchars((string)$_lgM['Body'], ENT_QUOTES) ?></p>
			<?php $_lgByline($_lgM); ?>
			<div class="pn-lg-removed-note">Removed by <b><?= $_lgBy !== '' ? $_lgBy : 'an officer' ?></b> on <?= htmlspecialchars((string)$_lgM['RemovedOn']) ?></div>
			<div class="pn-lg-removed-note pn-lg-removed-reason">Reason: <?= htmlspecialchars((string)$_lgM['RemovedReason'], ENT_QUOTES) ?></div>
		</div>
		<?php endforeach; ?>
	</div>
</details>
<?php endif; ?>

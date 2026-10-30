<?php
/**
 * Memory Wall tab body on a Legend's profile: one column, newest first.
 * Expects $Player, $Memories, $LoggedIn, $CanManageLegend, $ViewerPersona.
 * Posting and removing are wired in revised.js and reload the page, so a memory
 * is only ever rendered here, escaped; white-space: pre-line keeps its line breaks.
 */
$_lgMemories = is_array($Memories ?? null) ? $Memories : [];
$_lgPersona  = htmlspecialchars($Player['Persona'] ?? '', ENT_QUOTES);
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
	<div class="pn-form-error" id="pn-lg-error"></div>
	<textarea id="pn-lg-body" rows="4" maxlength="2000" placeholder="Your memory&hellip;" aria-label="Your memory"></textarea>
	<div class="pn-char-count" id="pn-lg-count">2000 characters remaining</div>
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
	<?php foreach ($_lgMemories as $_lgM): ?>
	<?php $_lgAuthor = htmlspecialchars((string)($_lgM['AuthorPersona'] ?? ''), ENT_QUOTES); ?>
	<div class="pn-lg-memory">
		<?php if (!empty($CanManageLegend)): ?>
		<span class="pn-lg-remove" data-id="<?= (int)$_lgM['MemoryId'] ?>">
			<button type="button" class="pn-lg-remove-link">Remove</button>
			<span class="pn-lg-remove-confirm" style="display:none">
				Remove this memory?
				<button type="button" class="pn-lg-remove-yes">Yes</button>
				<button type="button" class="pn-lg-remove-no">No</button>
			</span>
			<span class="pn-lg-remove-error" style="display:none"></span>
		</span>
		<?php endif; ?>
		<p><?= htmlspecialchars((string)$_lgM['Body'], ENT_QUOTES) ?></p>
		<div class="pn-lg-by">Remembered by
			<?php if ($_lgAuthor !== ''): ?><a href="<?= UIR ?>Player/profile/<?= (int)$_lgM['AuthorId'] ?>"><b><?= $_lgAuthor ?></b></a><?php else: ?><b>a community member</b><?php endif; ?>
			&middot; <?php /* The date part as stored: no timezone conversion. */ ?><?= htmlspecialchars(date('F j, Y', strtotime(substr((string)$_lgM['Created'], 0, 10) . ' 12:00:00'))) ?>
		</div>
	</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

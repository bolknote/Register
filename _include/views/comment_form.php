<?php

declare(strict_types = 1);

/** @var string $id */
/** @var string $action */
/** @var string[] $syntaxHelpItems */
/** @var callable $trans */
/** @var string $antispamToken */
/** @var string|null $name */
/** @var string|null $email */
/** @var bool|null $show_email */
/** @var bool|null $subscribed */
/** @var string|null $text */

$name       ??= '';
$email      ??= '';
$show_email ??= false;
$subscribed ??= false;
$text       ??= '';

?>
<h2 class="comment form" id="add-comment"><?php echo $trans('Post a comment'); ?></h2>
<form method="post" name="post_comment" action="<?php echo $action?>">
	<p class="input name">
		<label><?php echo $trans('Your name'); ?><br />
            <input type="text" name="name" value="<?php echo s2_htmlencode($name); ?>" maxlength="50" size="40" /></label>
	</p>
	<p class="input email">
		<label><?php echo $trans('Your email'); ?><br />
            <input type="text" name="email" value="<?php echo s2_htmlencode($email); ?>" maxlength="50" size="40" /></label><br />
		<label for="show_email" title="<?php echo $trans('Show email label title'); ?>"><input type="checkbox" id="show_email" name="show_email" <?php if ($show_email) echo 'checked="checked" '; ?>/><?php echo $trans('Show email label'); ?></label><br />
		<label for="subscribed" title="<?php echo $trans('Subscribe label title'); ?>"><input type="checkbox" id="subscribed" name="subscribed" <?php if ($subscribed) echo 'checked="checked" '; ?>/><?php echo $trans('Subscribe label'); ?></label>
	</p>
	<p class="input text">
		<label><?php echo $trans('Your comment'); ?><br />
            <textarea cols="50" rows="10" name="text"><?php echo s2_htmlencode($text); ?></textarea></label>
		<br />
		<small class="comment-syntax"><?php foreach ($syntaxHelpItems as $item) { echo $item . "\n"; } ?></small>
	</p>
	<p aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">
		<label>Homepage
			<input type="text" name="homepage" value="" tabindex="-1" autocomplete="off" /></label>
	</p>
	<input type="hidden" name="id" value="<?php echo s2_htmlencode($id); ?>" />
	<input type="hidden" name="antispam_token" value="<?php echo s2_htmlencode($antispamToken); ?>" />
	<p class="input buttons">
		<input type="submit" name="submit" value="<?php echo $trans('Submit'); ?>" />
		<input type="submit" name="preview" value="<?php echo $trans('Preview'); ?>" />
	</p>
</form>

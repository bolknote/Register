<?php

declare(strict_types = 1);

/**
 * @var array $content
 */

?>
<div class="year-content">
	<?php
	foreach ($content as $year_block): ?>
	<div class="year-block">
		<?php echo $year_block; ?>
	</div>
	<?php endforeach; ?>
</div>

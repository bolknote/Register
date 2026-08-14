<?php

declare(strict_types = 1);

/**
 * @var callable $thumbnailHtml
 * @var callable $formatDate
 * @var $title string
 * @var $link string
 * @var $descr string
 * @var $time string
 * @var $images \S2\Rose\Entity\Metadata\ImgCollection|\S2\Rose\Entity\Metadata\Img[]
 */
?>
<div class="search-result-img-preview">
<?php

foreach ($images as $image) {
    $img = $thumbnailHtml($image->getSrc(), $image->getWidth(), $image->getHeight(), 300, 75);
    echo '<a class="preview-link" href="', $link , '">', $img, '</a>';
}
?>
</div>
<p class="search-result">
	<a class="title" href="<?php echo $link; ?>">
        <?php echo $title; ?>
    </a><br />
	<?php echo trim($descr) ? $descr . '<br />' : '';  ?>
	<small class="stuff">
		<?php if (!empty($time)) echo $formatDate($time); ?>
	</small>
</p>

<?php
/** @var callable $param */
/** @var callable $trans */
/** @var string $locale */
/** @var string $basePath */

?>
<section class="admin-structure">
    <h1><?= $trans('Page structure') ?></h1>
    <div class="structure-toolbar">
        <button type="button" class="tree-tool expand" onclick="OpenAll(); return false;"
                title="<?php echo $trans('Expand'); ?>" aria-label="<?php echo $trans('Expand'); ?>">⤢</button>
        <button type="button" class="tree-tool collapse" onclick="CloseAll(); return false;"
                title="<?php echo $trans('Collapse'); ?>" aria-label="<?php echo $trans('Collapse'); ?>">⤡</button>
        <button type="button" class="tree-tool refresh"
                title="<?php echo $trans('Refresh'); ?>" aria-label="<?php echo $trans('Refresh'); ?>">↻</button>
        <button type="button" class="tree-create-page" id="create_page_button">+ <?= $trans('Create page') ?></button>
        <input type="search" name="search" id="search_field" placeholder="<?php echo $trans('Search'); ?>" autocomplete="off" />
    </div>
    <span id="context_buttons">
        <a href="#" class="context-link edit" title="<?php echo $trans('Edit'); ?>"><img id="context_edit" src="i/1.gif" alt=""></a>
        <a href="#" class="context-link comments" title="<?php echo $trans('Comments'); ?>"><img id="context_comments" src="i/1.gif" alt=""></a>
        <img id="context_add" src="i/1.gif" alt="<?= $trans('Create subarticle') ?>" title="<?= $trans('Create subarticle') ?>">
        <img id="context_delete" src="i/1.gif" alt="<?php echo $trans('Delete'); ?>" title="<?php echo $trans('Delete'); ?>">
    </span>
    <div id="tree" class="treetree" tabindex="0"></div>
</section>
<script src="<?php echo $basePath; ?>/_admin/js/ajax.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lang/<?php echo $locale; ?>/ui.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/jquery.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/jquery-tools.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/jquery.jstree.js"></script>
<script src="<?php echo $basePath; ?>/_admin/js/structure.js"></script>
<script>
    var sUrl = '<?php echo $basePath; ?>/_admin/ajax.php?';
    var new_page_pos = <?php echo json_encode($param('S2_ADMIN_NEW_POS'), JSON_THROW_ON_ERROR); ?>;
</script>

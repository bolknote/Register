<?php
/** @var callable $param */
/** @var callable $trans */
/** @var string $locale */
/** @var string $basePath */

?>
<section class="admin-structure"
         data-ajax-url="<?= s2_htmlencode($basePath . '/_admin/ajax.php?') ?>"
         data-new-page-position="<?= s2_htmlencode((string)$param('S2_ADMIN_NEW_POS')) ?>">
    <h1><?= $trans('Page structure') ?></h1>
    <nav class="section-tabs" aria-label="<?= s2_htmlencode($trans('Page display modes')) ?>">
        <a href="?entity=Article&amp;action=list"><?= s2_htmlencode($trans('List view')) ?></a>
        <a href="?entity=Site" aria-current="page"><?= s2_htmlencode($trans('Structure view')) ?></a>
    </nav>
    <div class="structure-toolbar">
        <button type="button" class="tree-tool expand"
                title="<?php echo $trans('Expand'); ?>" aria-label="<?php echo $trans('Expand'); ?>">⤢</button>
        <button type="button" class="tree-tool collapse"
                title="<?php echo $trans('Collapse'); ?>" aria-label="<?php echo $trans('Collapse'); ?>">⤡</button>
        <button type="button" class="tree-tool refresh"
                title="<?php echo $trans('Refresh'); ?>" aria-label="<?php echo $trans('Refresh'); ?>">↻</button>
        <button type="button" class="tree-create-page" id="create_page_button">+ <?= $trans('Create page') ?></button>
        <input type="search" name="search" id="search_field" placeholder="<?php echo $trans('Search'); ?>"
               aria-label="<?php echo $trans('Search'); ?>" autocomplete="off" />
    </div>
    <span id="context_buttons" role="group" aria-label="<?= $trans('Selected page actions') ?>">
        <a href="#" id="context_edit" class="context-action context-link edit"
           title="<?= $trans('Edit') ?>" aria-label="<?= $trans('Edit') ?>"><span aria-hidden="true">✎</span></a>
        <a href="#" id="context_comments" class="context-action context-link comments"
           title="<?= $trans('Comments') ?>" aria-label="<?= $trans('Comments') ?>"><span aria-hidden="true">💬</span></a>
        <button type="button" id="context_add" class="context-action"
                title="<?= $trans('Create child page') ?>" aria-label="<?= $trans('Create child page') ?>"><span aria-hidden="true">＋</span></button>
        <button type="button" id="context_delete" class="context-action is-dangerous"
                title="<?= $trans('Delete') ?>" aria-label="<?= $trans('Delete') ?>"><span aria-hidden="true">−</span></button>
    </span>
    <div id="tree" class="treetree" tabindex="0"></div>
</section>
<script src="<?php echo $basePath; ?>/_admin/js/ajax.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lang/<?php echo $locale; ?>/ui.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/jquery.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/jquery-tools.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/jquery.jstree.js"></script>
<script src="<?php echo $basePath; ?>/_admin/js/structure.js"></script>

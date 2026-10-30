<?php
/** Sanctioned posts are managed through the generic master data screen. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_super_admin();
redirect('masters/index.php?type=sanctioned_posts');

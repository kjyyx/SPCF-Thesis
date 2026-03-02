<?php
$role = $currentUser['role']; $pos = $currentUser['position'] ?? '';
$isAccounting = stripos($pos, 'Accounting') !== false;
$badgeColor = ($role === 'admin') ? 'danger' : (($role === 'employee') ? 'primary' : 'success');
?>
<nav class="navbar navbar-expand-lg fixed-top"><div class="container-fluid">
    <a href="<?= BASE_URL ?>" class="navbar-brand"><img src="<?= BASE_URL ?>assets/images/Sign-UM logo.png" alt="Logo"><span class="navbar-brand-divider">|</span><span class="navbar-brand-title text-muted fw-normal"><?= $pageTitle ?? 'Dashboard' ?></span></a>
    <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
        <div class="user-info me-3 hidden-mobile"><i class="bi bi-person-circle me-2"></i><span id="userDisplayName"><?= htmlspecialchars($currentUser['first_name'].' '.$currentUser['last_name']) ?></span><span class="badge ms-2 bg-<?= $badgeColor ?> rounded-pill"><?= strtoupper($role) ?></span></div>
        <div class="notification-bell me-4 position-relative" onclick="showNotifications()" style="cursor:pointer;"><i class="bi bi-bell fs-5 text-secondary hover-text-primary"></i><span id="notificationCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm" style="display:none;font-size:0.65rem;border:2px solid white;">0</span></div>
        <div class="dropdown me-3">
            <button class="btn btn-outline-light btn-sm dropdown-toggle rounded-pill" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear me-1"></i>Settings</button>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg" style="border-radius:24px; border:1px solid var(--color-border-subtle); padding:8px;">
                <li><a class="dropdown-item rounded-3" href="#" onclick="openProfileSettings()"><i class="bi bi-person-gear me-2"></i>Profile Settings</a></li>
                <li><a class="dropdown-item rounded-3" href="#" onclick="openChangePassword()"><i class="bi bi-key me-2"></i>Change Password</a></li>
                <?php if($role === 'admin'): ?><li><a class="dropdown-item rounded-3" href="#" onclick="openPreferences()"><i class="bi bi-sliders me-2"></i>Preferences</a></li><?php endif; ?>
                <li><a class="dropdown-item rounded-3" href="#" onclick="showHelp()"><i class="bi bi-question-circle me-2"></i>Help & Support</a></li>
                <li><hr class="dropdown-divider"></li>
                <?php 
                $links = [];
                if($role === 'admin') $links = ['calendar'=>['calendar-event','Calendar'],'dashboard'=>['shield-check','Admin Dashboard']];
                elseif($role === 'student') { $links = ['calendar'=>['calendar-event','Calendar'],'create-document'=>['file-plus','Create Document'],'track-document'=>['search','Track Document'],'upload-publication'=>['cloud-upload','Upload Publication'],'notifications'=>['bell','Notifications']]; if(!$isAccounting) $links['saf']=['cash-coin','SAF']; }
                elseif($role === 'employee') {
                    if(!$isAccounting) $links['calendar']=['calendar-event','Calendar'];
                    $links['notifications']=['bell','Notifications'];
                    if($isAccounting) $links['saf']=['cash-coin','SAF'];
                    if(in_array($pos, ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'])) $links['pubmat-approvals']=['file-earmark-text','Pubmat Approvals'];
                    if($pos === 'Physical Plant and Facilities Office (PPFO)') $links['pubmat-display']=['images','Pubmat Display'];
                }
                foreach($links as $path => $d) if(($currentPage ?? '') !== $path) echo "<li><a class='dropdown-item rounded-3' href='".BASE_URL."$path'><i class='bi bi-{$d[0]} me-2'></i>{$d[1]}</a></li>";
                ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item rounded-3 text-danger" href="<?= BASE_URL ?>logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</div></nav>
<script>
    window.currentUser = <?= json_encode(['id'=>$currentUser['id'],'firstName'=>$currentUser['first_name'],'lastName'=>$currentUser['last_name'],'role'=>$currentUser['role'],'email'=>$currentUser['email'],'department'=>$currentUser['department']??'','position'=>$currentUser['position']??'']) ?>;
    window.BASE_URL = "<?= BASE_URL ?>";
</script>
<script src="<?= BASE_URL ?>assets/js/navbar-settings.js"></script><script src="<?= BASE_URL ?>assets/js/global-notifications.js"></script>
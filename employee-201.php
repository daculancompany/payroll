<?php
// The 201 file used to be its own page. It is now two tabs on Employee
// Details (component/employee_201_tabs.php), so one person is one screen.
//
// This file stays as the forwarder: bookmarks, older page caches and anything
// that kept the address still land on the right tab instead of a blank page.
//
// It cannot use header('Location: …'): index.php INCLUDES this file after it
// has already sent the page, so the headers are long gone. The redirect is
// therefore done in the browser, with a real link for anyone whose scripting
// is off.

$e201_to = 'index.php?page=employee-details&id=' . (int) ($_GET['id'] ?? 0) . '#arrow-201';
?>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <div class="card rpt-card">
        <div class="card-body text-center py-5">
            <i class="ri-folder-user-line" style="font-size:34px;color:var(--app-primary,#673bb6);"></i>
            <h5 class="mt-3 mb-1">The 201 file moved</h5>
            <p class="text-muted mb-3">It is now a tab on the employee's own page. Taking you there&hellip;</p>
            <a class="btn btn-primary" href="<?= htmlspecialchars($e201_to, ENT_QUOTES) ?>">Open the 201 file</a>
        </div>
    </div>
</div>
</div>
</div>
<script>
    location.replace(<?= json_encode($e201_to) ?>);
</script>

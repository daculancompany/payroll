<style>
    .pos-name {
        font-weight: 600;
        font-size: 13px;
        color: #222;
    }

    .pos-index {
        font-size: 11px;
        color: #aaa;
        font-family: monospace;
        margin-right: 6px;
    }

  
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">
                            <i class="ri-briefcase-4-line me-2" style="color:#009688;"></i>Position
                        </h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Position</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header align-items-center d-flex">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-briefcase-4-line me-2" style="color:#009688;"></i>Position List
                            
                        </h4>
                        <button type="button" class="btn btn-sm text-white" style="background:#009688;border-color:#009688;"
                            data-bs-toggle="modal" data-bs-target="#modal">
                            <i class="ri-add-circle-line me-1"></i>Create Position
                        </button>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive mt-1 mb-1">
                            <table id="data-table" class="table table-hover table-bordered dt-responsive nowrap align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th><i class="ri-briefcase-4-line me-1"></i>Position</th>
                                        <th class="text-center" style="width:100px;"><i class="ri-settings-3-line me-1"></i>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $i = 0;
                                    $position = $conn->query("SELECT id, name AS position_name FROM position ORDER BY name ASC");
                                    while ($row = $position->fetch_assoc()):
                                        $i++;
                                    ?>
                                        <tr>
                                            <td>
                                                <!-- <span class="pos-index">#<?= str_pad($i, 2, '0', STR_PAD_LEFT) ?></span> -->
                                                <span class="pos-name"><?= htmlspecialchars($row['position_name']) ?></span>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                    id="<?= $row['id'] ?>"
                                                    name="<?= htmlspecialchars($row['position_name']) ?>"
                                                    onclick="edit_function(this)"
                                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Position">
                                                    <i class="ri-edit-line me-1"></i>Edit
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php include 'component/add_position_form.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
            new bootstrap.Tooltip(el, {
                trigger: 'hover'
            });
        });
    });
</script>
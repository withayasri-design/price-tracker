<?php

/**
 * Admin Page: Master Products Review
 *
 * Allows admins to:
 * - View products pending review (low confidence or unmatched)
 * - Manually link products to master products
 * - Create new master products
 * - View cross-platform price comparisons
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';
require_once __DIR__ . '/../../modules/matching/MasterProductService.php';

use Core\Auth;
use Core\Csrf;
use Modules\Matching\MasterProductService;

// Require admin access
Auth::requireAdmin();

$service = new MasterProductService($pdo);

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get products for review
$productsForReview = $service->getProductsForReview($perPage, $offset);
$totalReview = $service->getReviewCount();
$totalPages = ceil($totalReview / $perPage);

// Get CSRF token
$csrfToken = Csrf::generate();

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการ Master Products - Price Tracker Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .platform-badge {
            font-size: 0.75rem;
            text-transform: uppercase;
        }
        .confidence-high { color: #198754; }
        .confidence-medium { color: #ffc107; }
        .confidence-low { color: #fd7e14; }
        .confidence-review { color: #dc3545; }
        .suggestion-card {
            cursor: pointer;
            transition: all 0.2s;
        }
        .suggestion-card:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .suggestion-card.selected {
            border-color: #198754;
            background-color: #d1e7dd;
        }
        .similarity-bar {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            overflow: hidden;
        }
        .similarity-fill {
            height: 100%;
            background: linear-gradient(90deg, #dc3545 0%, #ffc107 50%, #198754 100%);
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="./dashboard.php">
                <i class="fas fa-chart-line me-2"></i>Price Tracker Admin
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i>กลับหน้าหลัก
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2">
                <div class="list-group">
                    <a href="./dashboard.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a href="./users.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-users me-2"></i>จัดการผู้ใช้
                    </a>
                    <a href="./master_products.php" class="list-group-item list-group-item-action active">
                        <i class="fas fa-link me-2"></i>Master Products
                        <?php if ($totalReview > 0): ?>
                            <span class="badge bg-danger float-end"><?= $totalReview ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="./scraping_monitor.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-spider me-2"></i>Scraping Monitor
                    </a>
                    <a href="./agent_monitor.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-robot me-2"></i>Agent Monitor
                    </a>
                    <a href="./settings.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-cog me-2"></i>ตั้งค่าระบบ
                    </a>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-10">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>
                        <i class="fas fa-link me-2"></i>Master Products
                        <small class="text-muted fs-5">- Cross-Platform Matching</small>
                    </h2>
                    <div>
                        <span class="badge bg-warning text-dark me-2">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <?= $totalReview ?> รายการรอตรวจสอบ
                        </span>
                    </div>
                </div>

                <?php if (empty($productsForReview)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        ไม่มีสินค้าที่รอตรวจสอบ
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header bg-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>สินค้าที่รอตรวจสอบ</span>
                                <span class="text-muted small">
                                    แสดง <?= count($productsForReview) ?> จาก <?= $totalReview ?> รายการ
                                </span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 80px;">Platform</th>
                                        <th>ชื่อสินค้า</th>
                                        <th style="width: 120px;">ราคาล่าสุด</th>
                                        <th style="width: 150px;">สถานะ</th>
                                        <th style="width: 120px;">การดำเนินการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($productsForReview as $product): ?>
                                        <tr data-product-id="<?= $product['product_id'] ?>">
                                            <td>
                                                <span class="badge platform-badge bg-secondary">
                                                    <?= htmlspecialchars($product['platform']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="fw-medium">
                                                    <?= htmlspecialchars($product['product_name'] ?? '-') ?>
                                                </div>
                                                <small class="text-muted">
                                                    <a href="<?= htmlspecialchars($product['product_url']) ?>" target="_blank">
                                                        <i class="fas fa-external-link-alt me-1"></i>ดูสินค้า
                                                    </a>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($product['last_price']): ?>
                                                    <strong>฿<?= number_format((float) $product['last_price'], 2) ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($product['master_product_id']): ?>
                                                    <span class="confidence-<?= $product['match_confidence'] ?>">
                                                        <i class="fas fa-link me-1"></i>
                                                        <?= ucfirst($product['match_confidence']) ?>
                                                    </span>
                                                    <div class="similarity-bar mt-1">
                                                        <div class="similarity-fill" style="width: <?= ($product['similarity_score'] ?? 0) * 100 ?>%"></div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">
                                                        <i class="fas fa-unlink me-1"></i>ยังไม่จับคู่
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-primary btn-match"
                                                        data-product-id="<?= $product['product_id'] ?>"
                                                        data-product-name="<?= htmlspecialchars($product['product_name'] ?? '') ?>">
                                                    <i class="fas fa-search me-1"></i>จับคู่
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="card-footer bg-white">
                                <nav>
                                    <ul class="pagination mb-0 justify-content-center">
                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Match Modal -->
    <div class="modal fade" id="matchModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-link me-2"></i>จับคู่สินค้า
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">สินค้าที่เลือก:</label>
                        <div id="selectedProductName" class="alert alert-info mb-0"></div>
                    </div>

                    <div id="suggestionsLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="mt-2">กำลังค้นหาสินค้าที่คล้ายกัน...</div>
                    </div>

                    <div id="suggestionsContainer" style="display: none;">
                        <label class="form-label fw-bold">เลือก Master Product:</label>
                        <div id="suggestionsList" class="row g-2 mb-3"></div>

                        <div class="border-top pt-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="matchOption" id="createNew" value="create">
                                <label class="form-check-label" for="createNew">
                                    <i class="fas fa-plus me-1"></i>สร้าง Master Product ใหม่
                                </label>
                            </div>
                            <div id="createNewForm" class="mt-2 ms-4" style="display: none;">
                                <input type="text" class="form-control" id="newCanonicalName" placeholder="ชื่อมาตรฐาน (Canonical Name)">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="button" class="btn btn-primary" id="confirmMatchBtn" disabled>
                        <i class="fas fa-check me-1"></i>ยืนยัน
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        let selectedProductId = null;
        let selectedMasterProductId = null;

        document.querySelectorAll('.btn-match').forEach(btn => {
            btn.addEventListener('click', function() {
                selectedProductId = this.dataset.productId;
                const productName = this.dataset.productName;

                document.getElementById('selectedProductName').textContent = productName;
                document.getElementById('suggestionsLoading').style.display = 'block';
                document.getElementById('suggestionsContainer').style.display = 'none';
                document.getElementById('confirmMatchBtn').disabled = true;
                selectedMasterProductId = null;

                const modal = new bootstrap.Modal(document.getElementById('matchModal'));
                modal.show();

                // Fetch suggestions
                fetch(`/api/matching/suggestions.php?product_id=${selectedProductId}`)
                    .then(res => res.json())
                    .then(data => {
                        document.getElementById('suggestionsLoading').style.display = 'none';
                        document.getElementById('suggestionsContainer').style.display = 'block';

                        const list = document.getElementById('suggestionsList');
                        list.innerHTML = '';

                        if (data.success && data.data.suggestions.length > 0) {
                            data.data.suggestions.forEach(s => {
                                const col = document.createElement('div');
                                col.className = 'col-12';
                                col.innerHTML = `
                                    <div class="card suggestion-card" data-master-id="${s.master_product_id}">
                                        <div class="card-body py-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div class="fw-medium">${s.canonical_name}</div>
                                                    <small class="text-muted">
                                                        ${s.brand ? `<span class="badge bg-light text-dark">${s.brand}</span>` : ''}
                                                        ${s.category || ''}
                                                    </small>
                                                </div>
                                                <div class="text-end">
                                                    <div class="badge ${s.confidence === 'high' ? 'bg-success' : s.confidence === 'medium' ? 'bg-warning' : 'bg-danger'}">
                                                        ${Math.round(s.similarity_score * 100)}%
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                                list.appendChild(col);
                            });

                            // Add click handlers
                            list.querySelectorAll('.suggestion-card').forEach(card => {
                                card.addEventListener('click', function() {
                                    list.querySelectorAll('.suggestion-card').forEach(c => c.classList.remove('selected'));
                                    this.classList.add('selected');
                                    selectedMasterProductId = this.dataset.masterId;
                                    document.getElementById('createNew').checked = false;
                                    document.getElementById('createNewForm').style.display = 'none';
                                    document.getElementById('confirmMatchBtn').disabled = false;
                                });
                            });
                        } else {
                            list.innerHTML = '<div class="col-12 text-muted">ไม่พบสินค้าที่คล้ายกัน</div>';
                        }

                        // Set default name for new master product
                        document.getElementById('newCanonicalName').value = productName;
                    })
                    .catch(err => {
                        console.error(err);
                        document.getElementById('suggestionsLoading').innerHTML =
                            '<div class="text-danger">เกิดข้อผิดพลาดในการโหลดข้อมูล</div>';
                    });
            });
        });

        // Handle create new option
        document.getElementById('createNew').addEventListener('change', function() {
            if (this.checked) {
                document.querySelectorAll('.suggestion-card').forEach(c => c.classList.remove('selected'));
                selectedMasterProductId = null;
                document.getElementById('createNewForm').style.display = 'block';
                document.getElementById('confirmMatchBtn').disabled = false;
            }
        });

        // Handle confirm
        document.getElementById('confirmMatchBtn').addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...';

            const isCreateNew = document.getElementById('createNew').checked;

            const payload = {
                product_id: parseInt(selectedProductId),
                csrf_token: csrfToken
            };

            if (isCreateNew) {
                payload.create_new = true;
                payload.canonical_name = document.getElementById('newCanonicalName').value;
            } else {
                payload.master_product_id = parseInt(selectedMasterProductId);
            }

            fetch('/api/matching/confirm_match.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Remove row from table
                    const row = document.querySelector(`tr[data-product-id="${selectedProductId}"]`);
                    if (row) row.remove();

                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('matchModal')).hide();

                    // Show success message
                    alert('จับคู่สินค้าสำเร็จ');
                } else {
                    alert('เกิดข้อผิดพลาด: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('เกิดข้อผิดพลาดในการบันทึก');
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check me-1"></i>ยืนยัน';
            });
        });
    </script>
</body>
</html>

<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/admin_helpers.php';

ensure_admin($conn);

$table = trim($_GET['table'] ?? $_POST['table'] ?? '');
if (!admin_table_allowed($table)) {
    header('Location: admin_dashboard.php');
    exit;
}

$message = '';
$messageClass = 'notice';

$columnsStmt = $conn->prepare("
    SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ORDER BY ORDINAL_POSITION
");
$columnsStmt->bind_param('s', $table);
$columnsStmt->execute();
$columnsResult = $columnsStmt->get_result();

$columns = [];
$primaryKeys = [];
$autoColumns = [];

while ($col = $columnsResult->fetch_assoc()) {
    $columns[] = $col;

    if (($col['COLUMN_KEY'] ?? '') === 'PRI') {
        $primaryKeys[] = $col['COLUMN_NAME'];
    }

    if (strpos(strtolower((string)$col['EXTRA']), 'auto_increment') !== false) {
        $autoColumns[] = $col['COLUMN_NAME'];
    }
}

if (!$columns || !$primaryKeys) {
    die('This table cannot be managed because no primary key was found.');
}

function admin_build_where_pk(array $primaryKeys, array $source, array $columns, array &$values, string &$types): string {
    $columnMap = [];
    foreach ($columns as $col) {
        $columnMap[$col['COLUMN_NAME']] = $col;
    }

    $parts = [];
    foreach ($primaryKeys as $pk) {
        $parts[] = bt($pk) . ' = ?';
        $values[] = $source['pk_' . $pk] ?? '';
        $types .= admin_column_type($columnMap[$pk]);
    }

    return implode(' AND ', $parts);
}


function admin_execute_or_throw(mysqli_stmt $stmt, mysqli $conn): void {
    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error ?: 'Unknown database error.';
        throw new Exception($error);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['admin_action'] ?? '';

        if ($action === 'create') {
            $insertCols = [];
            $placeholders = [];
            $values = [];
            $types = '';
            $hasNonBlankValue = false;
            $missingRequiredFields = [];

            foreach ($columns as $col) {
                $name = $col['COLUMN_NAME'];

                if (in_array($name, $autoColumns, true)) {
                    continue;
                }

                $rawExists = array_key_exists($name, $_POST);
                $rawValue = trim((string)($_POST[$name] ?? ''));

                if (!$rawExists) {
                    continue;
                }

                if ($rawValue !== '') {
                    $hasNonBlankValue = true;
                }

                $isRequiredField = (
                    strtoupper((string)$col['IS_NULLABLE']) === 'NO' &&
                    $col['COLUMN_DEFAULT'] === null &&
                    !in_array($name, $autoColumns, true)
                );

                if ($rawValue === '' && $isRequiredField) {
                    $missingRequiredFields[] = $name;
                    continue;
                }

                if ($rawValue === '' && strtoupper((string)$col['IS_NULLABLE']) === 'YES') {
                    $insertCols[] = bt($name);
                    $placeholders[] = '?';
                    $values[] = null;
                    $types .= admin_column_type($col);
                    continue;
                }

                if ($rawValue === '' && $col['COLUMN_DEFAULT'] !== null) {
                    continue;
                }

                $insertCols[] = bt($name);
                $placeholders[] = '?';
                $values[] = admin_post_value($col);
                $types .= admin_column_type($col);
            }

            if (!$hasNonBlankValue) {
                throw new Exception('Blank records are not allowed. Please enter at least one value before creating a record.');
            }

            if (!empty($missingRequiredFields)) {
                throw new Exception('Please complete the required field(s): ' . implode(', ', $missingRequiredFields) . '.');
            }

            if (!$insertCols) {
                throw new Exception('No values were provided for the new record.');
            }

            $sql = 'INSERT INTO ' . bt($table) . ' (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $conn->prepare($sql);
            admin_bind($stmt, $types, $values);
            admin_execute_or_throw($stmt, $conn);

            $message = 'Record created successfully.';
            $messageClass = 'notice notice--success';
        }

        if ($action === 'update') {
            $sets = [];
            $values = [];
            $types = '';

            foreach ($columns as $col) {
                $name = $col['COLUMN_NAME'];

                if (in_array($name, $primaryKeys, true) || in_array($name, $autoColumns, true)) {
                    continue;
                }

                if (!array_key_exists($name, $_POST)) {
                    continue;
                }

                $sets[] = bt($name) . ' = ?';
                $values[] = admin_post_value($col);
                $types .= admin_column_type($col);
            }

            if (!$sets) {
                throw new Exception('There are no editable fields for this table.');
            }

            $whereValues = [];
            $whereTypes = '';
            $where = admin_build_where_pk($primaryKeys, $_POST, $columns, $whereValues, $whereTypes);

            $sql = 'UPDATE ' . bt($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
            $stmt = $conn->prepare($sql);
            $values = array_merge($values, $whereValues);
            $types .= $whereTypes;
            admin_bind($stmt, $types, $values);
            admin_execute_or_throw($stmt, $conn);

            if ($stmt->affected_rows > 0) {
                $message = 'Record updated successfully.';
                $messageClass = 'notice notice--success';
            } else {
                $message = 'No changes detected. Nothing was updated.';
                $messageClass = 'notice';
            }
        }

        if ($action === 'delete') {
            $whereValues = [];
            $whereTypes = '';
            $where = admin_build_where_pk($primaryKeys, $_POST, $columns, $whereValues, $whereTypes);

            $sql = 'DELETE FROM ' . bt($table) . ' WHERE ' . $where;
            $stmt = $conn->prepare($sql);
            admin_bind($stmt, $whereTypes, $whereValues);
            admin_execute_or_throw($stmt, $conn);

            if ($stmt->affected_rows > 0) {
                $message = 'Record deleted successfully.';
                $messageClass = 'notice notice--success';
            } else {
                $message = 'No matching record was found. Nothing was deleted.';
                $messageClass = 'notice';
            }
        }
    }
} catch (Throwable $e) {
    $errorText = $e->getMessage();
    $action = $_POST['admin_action'] ?? '';

    $isForeignKeyError =
        stripos($errorText, 'foreign key constraint fails') !== false ||
        stripos($errorText, 'Cannot delete or update a parent row') !== false ||
        stripos($errorText, 'Cannot add or update a child row') !== false ||
        stripos($errorText, 'a foreign key constraint fails') !== false;

    if ($isForeignKeyError) {
        if ($action === 'delete') {
            if ($table === 'users') {
                $message = 'This user cannot be deleted because they are linked to other records such as buyer/seller/admin profiles, listings, orders, chats, reviews, wishlists, or complaints.';
            } elseif ($table === 'categories') {
                $message = 'This category cannot be deleted because one or more listings still use it.';
            } elseif ($table === 'listings') {
                $message = 'This listing cannot be deleted because it is linked to order items or other transaction history.';
            } elseif ($table === 'orders') {
                $message = 'This order cannot be deleted because it may be linked to order items, reviews, or complaints.';
            } elseif ($table === 'seller') {
                $message = 'This seller profile cannot be deleted because it is linked to listings, chats, reviews, or complaints.';
            } elseif ($table === 'buyer') {
                $message = 'This buyer profile cannot be deleted because it is linked to orders or reviews.';
            } elseif ($table === 'order_items') {
                $message = 'This order item cannot be deleted because another record still depends on it.';
            } else {
                $message = 'This record cannot be deleted because other records still depend on it.';
            }

            $message .= ' Delete or update the related records first, or keep this record to preserve database history.';
        } elseif ($action === 'update') {
            $message = 'This update cannot be completed because it would break a relationship with another table. Make sure any ID values you entered already exist in their related tables.';
        } elseif ($action === 'create') {
            $message = 'This record cannot be created because one of its foreign key values does not exist in the related table. Check the ID fields and try again.';
        } else {
            $message = 'This action cannot be completed because it would break a database relationship.';
        }
    } else {
        $message = 'Action failed: ' . $errorText;
    }

    $messageClass = 'notice notice--error';
}

$columnHeaders = '';
foreach ($columns as $col) {
    $columnHeaders .= '<th>' . h($col['COLUMN_NAME']) . '</th>';
}
$columnHeaders .= '<th>Actions</th>';

$recordsHtml = '';
$query = 'SELECT * FROM ' . bt($table);
$result = $conn->query($query);
$rowIndex = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $rowIndex++;
        $updateFormId = 'update_' . $rowIndex;
        $deleteFormId = 'delete_' . $rowIndex;

        $recordsHtml .= '<tr>';

        foreach ($columns as $col) {
            $name = $col['COLUMN_NAME'];
            $value = admin_input_value($row[$name] ?? null);
            $isPrimary = in_array($name, $primaryKeys, true);

            $recordsHtml .= '<td data-label="' . h($name) . '">';

            if ($isPrimary) {
                $recordsHtml .= '<input class="admin-input admin-input--readonly" type="text" value="' . h($value) . '" readonly>';
            } elseif (strtolower((string)$col['DATA_TYPE']) === 'text') {
                $recordsHtml .= '<textarea class="admin-input admin-textarea" name="' . h($name) . '" form="' . h($updateFormId) . '" data-original-value="' . h($value) . '">' . h($value) . '</textarea>';
            } else {
                $recordsHtml .= '<input class="admin-input" type="text" name="' . h($name) . '" value="' . h($value) . '" form="' . h($updateFormId) . '" data-original-value="' . h($value) . '">';
            }

            $recordsHtml .= '</td>';
        }

        $recordsHtml .= '<td class="admin-actions-cell" data-label="Actions">';

        $recordsHtml .= '<form id="' . h($updateFormId) . '" method="POST" class="admin-row-form" data-admin-form="update" data-confirm="Are you sure you want to save these changes?">';
        $recordsHtml .= '<input type="hidden" name="table" value="' . h($table) . '">';
        $recordsHtml .= '<input type="hidden" name="admin_action" value="update">';
        foreach ($primaryKeys as $pk) {
            $recordsHtml .= '<input type="hidden" name="pk_' . h($pk) . '" value="' . h($row[$pk] ?? '') . '">';
        }
        $recordsHtml .= '<button class="admin-icon-button" type="submit" title="Save changes">💾</button>';
        $recordsHtml .= '</form>';

        $recordsHtml .= '<form id="' . h($deleteFormId) . '" method="POST" class="admin-delete-form" data-admin-form="delete" data-confirm="Are you sure you want to delete this record? This cannot be undone.">';
        $recordsHtml .= '<input type="hidden" name="table" value="' . h($table) . '">';
        $recordsHtml .= '<input type="hidden" name="admin_action" value="delete">';
        foreach ($primaryKeys as $pk) {
            $recordsHtml .= '<input type="hidden" name="pk_' . h($pk) . '" value="' . h($row[$pk] ?? '') . '">';
        }
        $recordsHtml .= '<button class="admin-icon-button admin-icon-button--danger" type="submit" title="Delete record">🗑️</button>';
        $recordsHtml .= '</form>';

        $recordsHtml .= '</td></tr>';
    }
} else {
    $recordsHtml = '<tr><td colspan="' . (count($columns) + 1) . '">No records found in this table.</td></tr>';
}

$createFields = '';
foreach ($columns as $col) {
    $name = $col['COLUMN_NAME'];
    $isAuto = in_array($name, $autoColumns, true);
    $typeLabel = $col['COLUMN_TYPE'] ?? $col['DATA_TYPE'];

    $createFields .= '<div class="field admin-create-field">';
    $createFields .= '<label for="new_' . h($name) . '">' . h($name) . ' <small>' . h($typeLabel) . '</small></label>';

    $isRequiredCreateField = (
        !$isAuto &&
        strtoupper((string)$col['IS_NULLABLE']) === 'NO' &&
        $col['COLUMN_DEFAULT'] === null
    );
    $requiredAttr = $isRequiredCreateField ? ' required' : '';

    if ($isAuto) {
        $createFields .= '<input id="new_' . h($name) . '" type="text" placeholder="Auto generated" disabled>';
    } elseif (strtolower((string)$col['DATA_TYPE']) === 'text') {
        $createFields .= '<textarea id="new_' . h($name) . '" name="' . h($name) . '"' . $requiredAttr . '></textarea>';
    } else {
        $createFields .= '<input id="new_' . h($name) . '" type="text" name="' . h($name) . '"' . $requiredAttr . '>';
    }

    $createFields .= '</div>';
}

$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';

echo render_template(__DIR__ . '/templates/admin_table.html', [
    'TABLE_NAME' => h(admin_label($table)),
    'TABLE_KEY' => h($table),
    'MESSAGE' => $messageHtml,
    'COLUMN_HEADERS' => $columnHeaders,
    'RECORD_ROWS' => $recordsHtml,
    'CREATE_FIELDS' => $createFields,
]);
?>

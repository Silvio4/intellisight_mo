<?php

/**
 * Resolve the worksheet XML path for the sheet named "Costing Sheet".
 */
function costing_sheet_worksheet_path(ZipArchive $zip): string
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relationsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relationsXml === false) {
        throw new RuntimeException('Costing Sheet 模板不是有效的 XLSX 文件。');
    }

    $workbook = new DOMDocument();
    $relations = new DOMDocument();
    if (!$workbook->loadXML($workbookXml) || !$relations->loadXML($relationsXml)) {
        throw new RuntimeException('无法读取 Costing Sheet 模板结构。');
    }

    $xpath = new DOMXPath($workbook);
    $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $sheet = $xpath->query('//m:sheet[@name="Costing Sheet"]')->item(0);
    if (!$sheet instanceof DOMElement) {
        throw new RuntimeException('模板内找不到 Costing Sheet 工作表。');
    }
    $relationId = $sheet->getAttributeNS(
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'id'
    );

    $relationXpath = new DOMXPath($relations);
    $relationXpath->registerNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $relation = $relationXpath->query('//p:Relationship[@Id=' . costing_sheet_xpath_literal($relationId) . ']')->item(0);
    if (!$relation instanceof DOMElement) {
        throw new RuntimeException('模板内 Costing Sheet 工作表关系无效。');
    }

    $target = ltrim(str_replace('\\', '/', $relation->getAttribute('Target')), '/');
    $target = preg_replace('#^(?:\.\./)+#', '', $target);
    return strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
}

function costing_sheet_xpath_literal(string $value): string
{
    if (strpos($value, "'") === false) {
        return "'" . $value . "'";
    }
    return '"' . str_replace('"', '', $value) . '"';
}

/**
 * Set a cell without changing its existing style, border, width, or row height.
 */
function costing_sheet_set_cell(DOMDocument $document, DOMXPath $xpath, string $reference, $value, bool $numeric = false): void
{
    if (!preg_match('/^([A-Z]+)(\d+)$/', $reference, $matches)) {
        throw new InvalidArgumentException('无效的单元格位置：' . $reference);
    }
    $rowNumber = (int)$matches[2];
    $sheetData = $xpath->query('//m:sheetData')->item(0);
    if (!$sheetData instanceof DOMElement) {
        throw new RuntimeException('Costing Sheet 工作表缺少 sheetData。');
    }

    $row = $xpath->query('//m:sheetData/m:row[@r="' . $rowNumber . '"]')->item(0);
    if (!$row instanceof DOMElement) {
        $row = $document->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'row');
        $row->setAttribute('r', (string)$rowNumber);
        $insertBefore = null;
        foreach ($xpath->query('./m:row', $sheetData) as $existingRow) {
            if ((int)$existingRow->getAttribute('r') > $rowNumber) {
                $insertBefore = $existingRow;
                break;
            }
        }
        $sheetData->insertBefore($row, $insertBefore);
    }

    $cell = $xpath->query('./m:c[@r="' . $reference . '"]', $row)->item(0);
    if (!$cell instanceof DOMElement) {
        $cell = $document->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c');
        $cell->setAttribute('r', $reference);
        // Product rows beyond the preformatted template inherit the matching
        // column's style from the first product row.
        if ($rowNumber > 12) {
            $styleSource = $xpath->query('//m:c[@r="' . $matches[1] . '12"]')->item(0);
            if ($styleSource instanceof DOMElement && $styleSource->hasAttribute('s')) {
                $cell->setAttribute('s', $styleSource->getAttribute('s'));
            }
        }
        $row->appendChild($cell);
    }
    while ($cell->firstChild) {
        $cell->removeChild($cell->firstChild);
    }

    if ($numeric && is_numeric($value)) {
        $cell->removeAttribute('t');
        $cell->appendChild($document->createElementNS($cell->namespaceURI, 'v', (string)(0 + $value)));
        return;
    }

    $cell->setAttribute('t', 'inlineStr');
    $inlineString = $document->createElementNS($cell->namespaceURI, 'is');
    $text = $document->createElementNS($cell->namespaceURI, 't');
    $stringValue = (string)$value;
    if ($stringValue !== trim($stringValue)) {
        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    }
    $text->appendChild($document->createTextNode($stringValue));
    $inlineString->appendChild($text);
    $cell->appendChild($inlineString);
}

/**
 * Generate a task's costing sheet and mark the task as built.
 */
function generate_costing_sheet(int $taskId): array
{
    if ($taskId < 1) {
        throw new InvalidArgumentException('缺少有效的 task_id。');
    }
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
        throw new RuntimeException('服务器缺少生成 XLSX 所需的 Zip/XML 扩展。');
    }

    $template = dirname(__DIR__) . '/template/costing_sheet_v1.xlsx';
    if (!is_file($template) || !is_readable($template)) {
        throw new RuntimeException('找不到模板 template/costing_sheet_v1.xlsx。');
    }

    $pdo = db();
    $pdo->beginTransaction();
    $temporaryFile = null;
    try {
        $statement = $pdo->prepare('SELECT * FROM contract_forms WHERE id=:id LIMIT 1 FOR UPDATE');
        $statement->execute([':id' => $taskId]);
        $task = $statement->fetch();
        if (!$task) {
            throw new RuntimeException('找不到对应任务。');
        }
        if (!in_array((int)$task['status'], [5, 6], true)) {
            throw new RuntimeException('仅待建表的任务可生成 Costing Sheet。');
        }

        $filename = 'costing_sheet_' . format_task_no($taskId) . '.xlsx';
        $directory = dirname(__DIR__) . '/files/costing_sheet';
        $destination = $directory . '/' . $filename;
        if ((int)$task['status'] === 6 && is_file($destination)) {
            $pdo->commit();
            return ['filename' => $filename, 'path' => $destination, 'generated' => false];
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建 Costing Sheet 文件目录。');
        }

        $temporaryFile = tempnam($directory, '.costing_sheet_');
        if ($temporaryFile === false || !copy($template, $temporaryFile)) {
            throw new RuntimeException('无法复制 Costing Sheet 模板。');
        }
        $zip = new ZipArchive();
        if ($zip->open($temporaryFile) !== true) {
            throw new RuntimeException('无法打开 Costing Sheet 模板。');
        }
        try {
            $worksheetPath = costing_sheet_worksheet_path($zip);
            $worksheetXml = $zip->getFromName($worksheetPath);
            if ($worksheetXml === false) {
                throw new RuntimeException('无法读取 Costing Sheet 工作表。');
            }
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->preserveWhiteSpace = false;
            if (!$document->loadXML($worksheetXml)) {
                throw new RuntimeException('Costing Sheet 工作表 XML 无效。');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $fixedCells = [
                'D2' => $task['sales_person'], 'D4' => $task['po_no'], 'D5' => $task['customer_name'],
                'D8' => date('Y/n/j'), 'K2' => $task['customer_delivery_address'],
                'K3' => $task['end_user_name'], 'K4' => $task['end_user_contact'],
                'K5' => $task['end_user_email'],
            ];
            foreach ($fixedCells as $cell => $value) {
                costing_sheet_set_cell($document, $xpath, $cell, $value ?? '');
            }

            $columns = [];
            foreach (['pid', 'vendor_part_no', 'description', 'qty', 'price_currency', 'unit_price'] as $field) {
                $columns[$field] = split_result_values($task[$field]);
            }
            $productCount = count($columns['pid']);
            if ($productCount < 1) {
                throw new RuntimeException('任务没有可写入的产品资料。');
            }
            foreach ($columns as $field => $values) {
                if (count($values) !== $productCount) {
                    throw new RuntimeException($field . ' 的产品数量与 pid 不一致。');
                }
            }
            for ($index = 0; $index < $productCount; $index++) {
                $row = 12 + $index;
                costing_sheet_set_cell($document, $xpath, 'A' . $row, $index + 1, true);
                costing_sheet_set_cell($document, $xpath, 'B' . $row, 'JOSM');
                costing_sheet_set_cell($document, $xpath, 'C' . $row, 'Product');
                costing_sheet_set_cell($document, $xpath, 'D' . $row, $columns['pid'][$index]);
                costing_sheet_set_cell($document, $xpath, 'E' . $row, $columns['vendor_part_no'][$index]);
                costing_sheet_set_cell($document, $xpath, 'F' . $row, $columns['description'][$index]);
                costing_sheet_set_cell($document, $xpath, 'G' . $row, $columns['qty'][$index], true);
                costing_sheet_set_cell($document, $xpath, 'J' . $row, $columns['price_currency'][$index]);
                costing_sheet_set_cell($document, $xpath, 'K' . $row, $columns['unit_price'][$index], true);
                $total = is_numeric($columns['qty'][$index]) && is_numeric($columns['unit_price'][$index])
                    ? (float)$columns['qty'][$index] * (float)$columns['unit_price'][$index]
                    : '';
                costing_sheet_set_cell($document, $xpath, 'M' . $row, $total, $total !== '');
            }
            if (!$zip->addFromString($worksheetPath, $document->saveXML())) {
                throw new RuntimeException('无法写入 Costing Sheet 工作表。');
            }
        } finally {
            $zip->close();
        }

        if (is_file($destination) && !unlink($destination)) {
            throw new RuntimeException('无法替换旧的 Costing Sheet。');
        }
        if (!rename($temporaryFile, $destination)) {
            throw new RuntimeException('无法保存生成的 Costing Sheet。');
        }
        $temporaryFile = null;
        $pdo->prepare('UPDATE contract_forms SET status=6,costing_sheet_generated_at=NOW(),submitted_approval_at=NOW(),approval_round=approval_round+1,completed_at=NULL,rejection_reason=NULL,updated_at=NOW() WHERE id=:id')
            ->execute([':id' => $taskId]);
        $pdo->prepare("INSERT INTO logs (operator,task_id,operation_time,operation_content,task_status) VALUES ('API:costing_sheet',:task_id,NOW(),'生成 Costing Sheet并提交审批',6)")
            ->execute([':task_id' => $taskId]);
        $pdo->prepare("INSERT INTO contract_approvals (task_id,approval_round,action,operator_id,operator_name,created_at) SELECT id,approval_round,'submit',created_by,created_by_name,NOW() FROM contract_forms WHERE id=:id")
            ->execute([':id' => $taskId]);
        $pdo->commit();
        return ['filename' => $filename, 'path' => $destination, 'generated' => true];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($temporaryFile && is_file($temporaryFile)) {
            @unlink($temporaryFile);
        }
        throw $exception;
    }
}

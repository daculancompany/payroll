<?php
/**
 * HR module 2 — 201 documents (contract, IDs, clearances, medical, diploma…).
 *
 * Same storage rules as the profile photo (see hr_class.php): files live in
 * storage/hr/docs/ under a name WE generate, the column holds that name only,
 * and every read goes through hr-file.php?kind=doc&id=…, which asks who is
 * asking before streaming a byte.
 *
 * Two ways in:
 *   HR uploads to any employee            → source 'hr', verified at once
 *   The employee uploads from the portal  → source 'employee', waits for HR
 *
 * `visible_to_employee` lets HR keep a file on record without showing it in
 * the portal (an investigation report, say). An employee's own upload is
 * always visible to them.
 */
trait HrDocumentsModule
{
    /** 10 MB: a scanned multi-page PDF fits; a video does not. */
    private static $DOC_MAX_BYTES = 10485760;

    /** Sniffed type → extension. No Office files: they can carry macros. */
    public static function docMimes()
    {
        return [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
        ];
    }

    public static function docCategories()
    {
        return [
            'Contract', 'Valid ID', 'Government ID', 'NBI Clearance', 'Police Clearance',
            'Medical', 'Diploma / TOR', 'Certificate', 'Resume', 'Memo / NTE',
            'Clearance', 'Other',
        ];
    }

    /** What an employee may submit themselves. */
    public static function selfDocCategories()
    {
        return [
            'Valid ID', 'Government ID', 'NBI Clearance', 'Police Clearance',
            'Medical', 'Diploma / TOR', 'Certificate', 'Resume', 'Other',
        ];
    }

    /** @return array documents of one employee, newest first */
    public function documents($employee_id, $forEmployee = false)
    {
        if (!$this->tableExists('hr_documents')) return [];
        $sql = "SELECT d.*, u.name AS uploaded_by_name, v.name AS verified_by_name
                FROM hr_documents d
                LEFT JOIN users u ON u.id = d.uploaded_by
                LEFT JOIN users v ON v.id = d.verified_by
                WHERE d.employee_id = ?"
             . ($forEmployee ? " AND (d.visible_to_employee = 1 OR d.source = 'employee')" : '')
             . " ORDER BY d.created_at DESC, d.id DESC";
        return $this->rowsFor($sql, $employee_id);
    }

    public function documentRow($id)
    {
        $rows = $this->rowsFor("SELECT * FROM hr_documents WHERE id = ?", $id);
        return $rows[0] ?? null;
    }

    private function docFolder()
    {
        return __DIR__ . '/../../' . self::STORAGE_DIR . '/docs';
    }

    /** Absolute path of a stored document, or null when the name is not ours. */
    public function documentPath($file_name)
    {
        $file_name = (string) $file_name;
        if (!preg_match('/^doc\d+_[a-f0-9]{16}\.(pdf|jpg|png|webp)$/', $file_name)) return null;
        $root = realpath($this->docFolder());
        $real = $root ? realpath($root . '/' . $file_name) : false;
        if (!$real || strpos($real, $root) !== 0) return null;
        return $real;
    }

    /**
     * Validate + store one upload. $meta: category/title/expiry_date/notes/
     * visible_to_employee, already from the caller's allowed set.
     */
    private function writeDocument($employee_id, array $file, array $src, array $categories, $source, $actor)
    {
        if ($e = $this->needTable('hr_documents')) return $e;
        if ($employee_id <= 0 || !$this->employeeExists($employee_id)) return $this->fail('That employee no longer exists.');

        list($category, $err) = $this->textField($src, 'category', 'Category', 40, true, $categories);
        if ($err) return $this->fail($err);
        list($title, $err) = $this->textField($src, 'title', 'Title', 150);
        if ($err) return $this->fail($err);
        list($expiry, $err) = $this->dateField($src, 'expiry_date', 'Expiry date');
        if ($err) return $this->fail($err);
        list($notes, $err) = $this->textField($src, 'notes', 'Notes', 255);
        if ($err) return $this->fail($err);

        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) return $this->fail('Choose a file first.');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return $this->fail('That file is too large. The limit is 10 MB.');
        }
        if ($err !== UPLOAD_ERR_OK) return $this->fail('The file did not upload. Please try again.');

        $tmp = $file['tmp_name'] ?? '';
        if (!$tmp || !is_uploaded_file($tmp)) return $this->fail('The file did not upload. Please try again.');
        $size = (int) filesize($tmp);
        if ($size <= 0)                       return $this->fail('That file is empty.');
        if ($size > self::$DOC_MAX_BYTES)     return $this->fail('That file is too large. The limit is 10 MB.');

        // The sniffed type decides, never the extension the browser claimed.
        $mime  = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $mimes = self::docMimes();
        if (!isset($mimes[$mime])) return $this->fail('Upload a PDF, JPEG, PNG or WebP file.');
        if ($mime !== 'application/pdf' && @getimagesize($tmp) === false) {
            return $this->fail('That image could not be read.');
        }

        $folder = $this->storageFolder('docs');
        if (!$folder) return $this->fail('The document folder could not be created on the server.');

        $name = sprintf('doc%d_%s.%s', $employee_id, bin2hex(random_bytes(8)), $mimes[$mime]);
        if (!@move_uploaded_file($tmp, $folder . '/' . $name)) {
            return $this->fail('The file could not be saved on the server.');
        }

        // The client's filename is display text only — capped, never a path.
        $original = mb_substr(basename((string) ($file['name'] ?? '')), 0, 190) ?: null;
        if ($title === null) {
            $title = $original ? mb_substr(pathinfo($original, PATHINFO_FILENAME), 0, 150) : $category;
        }

        $visible    = $source === 'employee' ? 1 : (!empty($src['visible_to_employee']) ? 1 : 0);
        $verifiedAt = $source === 'hr' ? date('Y-m-d H:i:s') : null;
        $verifiedBy = $source === 'hr' ? $actor : null;

        $stmt = $this->db->prepare("INSERT INTO hr_documents
            (employee_id, category, title, file_name, original_name, mime, size_bytes, expiry_date, notes,
             visible_to_employee, source, verified_at, verified_by, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            @unlink($folder . '/' . $name);
            return $this->fail('Could not record the document.');
        }
        $stmt->bind_param('isssssississii',
            $employee_id, $category, $title, $name, $original, $mime, $size, $expiry, $notes,
            $visible, $source, $verifiedAt, $verifiedBy, $actor);
        if (!$stmt->execute()) {
            @unlink($folder . '/' . $name);
            return $this->fail('Could not record the document.');
        }

        return ['result' => true, 'id' => (int) $this->db->insert_id, 'message' => 'Document uploaded.'];
    }

    /** HR uploads a file to any employee's 201. */
    public function hr_doc_upload()
    {
        if (!$this->canWrite()) return $this->denied();
        return $this->writeDocument((int) ($_POST['employee_id'] ?? 0), $_FILES['file'] ?? [], $_POST,
            self::docCategories(), 'hr', $this->actor());
    }

    /** The employee submits a file from the portal; HR verifies it later. */
    public function my_doc_upload()
    {
        $me = $this->selfId();
        if ($me <= 0) return $this->fail('Please sign in again.');
        $res = $this->writeDocument($me, $_FILES['file'] ?? [], $_POST, self::selfDocCategories(), 'employee', null);
        if ($res['result']) {
            $this->notifyHrStaff('Document submitted',
                $this->employeeName($me) . ' uploaded a document for their 201 file.',
                'ri-file-upload-line', 'info', 'index.php?page=employee-details&id=' . $me . '#arrow-hrdocs');
            $res['message'] = 'Uploaded. HR will check it.';
        }
        return $res;
    }

    /** HR edits the details of a document (not the file). */
    public function hr_doc_update()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_documents')) return $e;

        $id          = (int) ($_POST['id'] ?? 0);
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        if ($id <= 0 || !$this->ownsRow('hr_documents', $id, $employee_id)) {
            return $this->fail('That document is not on this employee.');
        }

        list($category, $err) = $this->textField($_POST, 'category', 'Category', 40, true, self::docCategories());
        if ($err) return $this->fail($err);
        list($title, $err) = $this->textField($_POST, 'title', 'Title', 150, true);
        if ($err) return $this->fail($err);
        list($expiry, $err) = $this->dateField($_POST, 'expiry_date', 'Expiry date');
        if ($err) return $this->fail($err);
        list($notes, $err) = $this->textField($_POST, 'notes', 'Notes', 255);
        if ($err) return $this->fail($err);
        $visible = !empty($_POST['visible_to_employee']) ? 1 : 0;

        $stmt = $this->db->prepare("UPDATE hr_documents
            SET category = ?, title = ?, expiry_date = ?, notes = ?, visible_to_employee = ?
            WHERE id = ? AND employee_id = ?");
        $stmt->bind_param('ssssiii', $category, $title, $expiry, $notes, $visible, $id, $employee_id);
        if (!$stmt->execute()) return $this->fail('Could not save the document.');
        return ['result' => true, 'message' => 'Document updated.'];
    }

    /** HR confirms a document the employee submitted. */
    public function hr_doc_verify()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_documents')) return $e;

        $id          = (int) ($_POST['id'] ?? 0);
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        if ($id <= 0 || !$this->ownsRow('hr_documents', $id, $employee_id)) {
            return $this->fail('That document is not on this employee.');
        }
        $actor = $this->actor();
        $stmt  = $this->db->prepare("UPDATE hr_documents SET verified_at = NOW(), verified_by = ?
                                     WHERE id = ? AND employee_id = ? AND verified_at IS NULL");
        $stmt->bind_param('iii', $actor, $id, $employee_id);
        $stmt->execute();
        if (!$stmt->affected_rows) return $this->fail('That document is already verified.');
        return ['result' => true, 'message' => 'Document verified.'];
    }

    private function removeDocument($id, $employee_id)
    {
        $doc = $this->documentRow($id);
        if (!$doc || (int) $doc['employee_id'] !== (int) $employee_id) {
            return $this->fail('That document is not on this employee.');
        }
        $stmt = $this->db->prepare("DELETE FROM hr_documents WHERE id = ? AND employee_id = ?");
        $stmt->bind_param('ii', $id, $employee_id);
        if (!$stmt->execute() || !$stmt->affected_rows) return $this->fail('Could not delete the document.');
        $path = $this->documentPath($doc['file_name']);
        if ($path) @unlink($path);
        return ['result' => true, 'message' => 'Document deleted.'];
    }

    public function hr_doc_delete()
    {
        if (!$this->canWrite()) return $this->denied();
        if ($e = $this->needTable('hr_documents')) return $e;
        return $this->removeDocument((int) ($_POST['id'] ?? 0), (int) ($_POST['employee_id'] ?? 0));
    }

    /**
     * The employee withdraws a file THEY uploaded, while HR has not verified
     * it yet. Once verified it is part of the record and stays.
     */
    public function my_doc_delete()
    {
        $me = $this->selfId();
        if ($me <= 0) return $this->fail('Please sign in again.');
        if ($e = $this->needTable('hr_documents')) return $e;

        $doc = $this->documentRow((int) ($_POST['id'] ?? 0));
        if (!$doc || (int) $doc['employee_id'] !== $me || $doc['source'] !== 'employee') {
            return $this->fail('You can only remove files you uploaded.');
        }
        if ($doc['verified_at']) return $this->fail('HR has already verified this file, so it stays on your record.');
        return $this->removeDocument((int) $doc['id'], $me);
    }
}

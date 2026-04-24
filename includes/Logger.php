<?php
class Logger {
    public static function log(string $level, string $message, ?int $selectionId = null, ?int $uploadId = null, ?string $context = null): void {
        try {
            $db = getDB();
            $stmt = $db->prepare("INSERT INTO logs (level, message, context, selection_id, upload_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$level, $message, $context, $selectionId, $uploadId]);
        } catch (\Exception $e) {
            error_log("Logger error: " . $e->getMessage());
        }
    }
}

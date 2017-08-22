<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A Handler to store attachments sent in e-mails as private files.
 *
 * @package    core_message
 * @copyright  2014 Andrew Nicols
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\message\inbound;

defined('MOODLE_INTERNAL') || die();

/**
 * A Handler to store attachments sent in e-mails as private files.
 *
 * @package    core
 * @copyright  2014 Andrew Nicols
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class private_files_handler extends handler {

    /**
     * Return a description for the current handler.
     *
     * @return string
     */
    public function get_description() {
        return get_string('private_files_handler', 'moodle');
    }

    /**
     * Return a short name for the current handler.
     * This appears in the admin pages as a human-readable name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('private_files_handler_name', 'moodle');
    }

    /**
     * Process a message received and validated by the Inbound Message processor.
     *
     * @throws \core\message\inbound\processing_failed_exception
     * @param \stdClass $record The Inbound Message record
     * @param \stdClass $data The message data packet
     * @return bool Whether the message was successfully processed.
     */
    public function process_message(\stdClass $record, \stdClass $data) {
        global $USER, $CFG;

        $context = \context_user::instance($USER->id);

        if (!has_capability('moodle/user:manageownfiles', $context)) {
            throw new \core\message\inbound\processing_failed_exception('emailtoprivatefilesdenied', 'moodle', $data);
        }

        // Initial setup.
        $component  = 'user';
        $filearea   = 'private';
        $itemid     = 0;
        $license    = $CFG->sitedefaultlicense;
        $author     = fullname($USER);

        // Determine the quota space for this user.
        $maxbytes = $CFG->userquota;
        if (has_capability('moodle/user:ignoreuserquota', $context)) {
            $maxbytes = USER_CAN_IGNORE_FILE_SIZE_LIMITS;
        }

        // Keep track of files which were uploaded, and which were skipped.
        $skippedfiles   = array();
        $uploadedfiles  = array();
        $failedfiles    = array();

        $fs = get_file_storage();
        foreach ($data->attachments as $attachmenttype => $attachments) {
            foreach ($attachments as $attachment) {
                mtrace("--- Processing attachment '{$attachment->filename}'");

                if (file_is_draft_area_limit_reached($itemid, $maxbytes, $attachment->filesize)) {
                    // The user quota will be exceeded if this file is included.
                    $skippedfiles[] = $attachment;
                    mtrace("---- Skipping attacment. User will be over quota.");
                    continue;
                }

                // Create a new record for this file.
                $record = new \stdClass();
                $record->filearea   = $filearea;
                $record->component  = $component;
                $record->filepath   = '/';
                $record->itemid     = $itemid;
                $record->license    = $license;
                $record->author     = $author;
                $record->contextid  = $context->id;
                $record->userid     = $USER->id;

                $record->filename = $fs->get_unused_filename($context->id, $record->component, $record->filearea,
                        $record->itemid, $record->filepath, $attachment->filename);

                mtrace("--> Attaching {$record->filename} to " .
                       "/{$record->contextid}/{$record->component}/{$record->filearea}/" .
                       "{$record->itemid}{$record->filepath}{$record->filename}");

                if ($fs->create_file_from_string($record, $attachment->content)) {
                    // File created successfully.
                    mtrace("---- File uploaded successfully as {$record->filename}.");
                    $uploadedfiles[] = $attachment;
                } else {
                    mtrace("---- Skipping attacment. Unknown failure during creation.");
                    $failedfiles[] = $attachment;
                }
            }
        }

        // TODO send the user a confirmation e-mail.
        // Note, some files may have failed because the user has been pushed over quota. This does not constitute a failure.

        return true;
    }
}

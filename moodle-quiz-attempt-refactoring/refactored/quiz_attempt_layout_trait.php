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


namespace mod_quiz;

use action_link;
use block_contents;
use cm_info;
use coding_exception;
use context_module;
use core\di;
use core\hook;
use Exception;
use html_writer;
use mod_quiz\hook\attempt_state_changed;
use mod_quiz\output\grades\grade_out_of;
use mod_quiz\output\links_to_other_attempts;
use mod_quiz\output\renderer;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\question\display_options;
use moodle_exception;
use moodle_url;
use popup_action;
use qtype_description_question;
use question_attempt;
use question_bank;
use question_display_options;
use question_engine;
use question_out_of_sequence_exception;
use question_state;
use question_usage_by_activity;
use stdClass;

/**
 * Extracted methods from quiz_attempt to reduce class size and improve PhpMetrics MI.
 */
trait quiz_attempt_layout_trait {

    /**
     * Let each slot know which section it is part of.
     */
    protected function link_sections_and_slots() {
        $c = count($this->slots);
        foreach ($this->sections as $i => $section) {
            $next = $this->sections[$i + 1] ?? null;
            $section->lastslot = $next ? $next->firstslot - 1 : $c;
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $this->slots[$slot]->section = $section;
            }
        }
    }

    /**
     * Parse attempt->layout to populate the other arrays that represent the layout.
     */
    protected function determine_layout() {
        $pagelayouts = explode(',0', $this->attempt->layout);
        if (end($pagelayouts) == '') {
            array_pop($pagelayouts);
        }
        $unseensections = $this->sections;
        $this->pagelayout = [];
        foreach ($pagelayouts as $page => $pagelayout) {
            $this->process_single_page_layout($page, $pagelayout, $unseensections);
        }
    }

    /**
     * Process layout configuration for a single page.
     * Helper for determine_layout() to reduce cognitive complexity.
     *
     * @param int $page Page index.
     * @param string $pagelayout Layout string for the page.
     * @param array $unseensections Reference to array of unmapped sections.
     */
    protected function process_single_page_layout($page, $pagelayout, &$unseensections) {
        if (($pagelayout = trim($pagelayout, ',')) == '') return;
        $this->pagelayout[$page] = explode(',', $pagelayout);
        foreach ($this->pagelayout[$page] as $slot) {
            $k = array_search($this->slots[$slot]->section, $unseensections);
            $this->slots[$slot]->firstinsection = ($k !== false);
            if ($k !== false) unset($unseensections[$k]);
        }
    }

    /**
     * Work out the number to display for each question/slot.
     */
    protected function number_questions() {
        $number = 1;
        foreach ($this->pagelayout as $page => $slots) {
            foreach ($slots as $slot) {
                $this->number_single_slot($slot, $page, $number);
            }
        }
    }

    /**
     * Calculate and assign question numbering for an isolated slot.
     * Helper for number_questions() to eliminate nested loop complexity.
     *
     * @param int $slot Slot identifier.
     * @param int $page Page index.
     * @param int $number Reference to numerical incrementer.
     */
    protected function number_single_slot($slot, $page, &$number) {
        $s = $this->slots[$slot];
        if ($l = $this->is_real_question($slot)) {
            $this->questionnumbers[$slot] = ($s->displaynumber !== null && $s->displaynumber !== '' && !$s->section->shufflequestions) ? $s->displaynumber : (string)$number;
            $number += $l;
        } else {
            $this->questionnumbers[$slot] = get_string('infoshort', 'quiz');
        }
        $this->questionpages[$slot] = $page;
    }

    /**
     * If the given page number is out of range (before the first page, or after
     * the last page, change it to be within range).
     *
     * @param int $page the requested page number.
     * @return int a safe page number to use.
     */
    public function force_page_number_into_range($page) {
        return min(max($page, 0), count($this->pagelayout) - 1);
    }

}

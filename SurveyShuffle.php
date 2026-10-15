<?php
/**
 * Shuffles Surveys; Displays selected surveys to respondents in a random order.
 * Order can be random, or predetermined by a field that stores an instrument sequence.
 *
 * @author Aidan Wilson <aidan.wilson@intersect.org.au>
 * @link https://github.com/jangari/redcap_survey_shuffle
 */

namespace INTERSECT\SurveyShuffle;

use ExternalModules\AbstractExternalModule;
use REDCap;

class SurveyShuffle extends AbstractExternalModule
{
    private $debug = false; // Activer/désactiver les logs

    /**
     * Hook triggered when a survey is completed
     */
    public function redcap_survey_complete(
        $project_id,
        $record,
        $instrument,
        $event_id,
        $group_id,
        $survey_hash,
        $response_id,
        $repeat_instance
    ) {
        $this->log("========== SURVEY COMPLETE HOOK TRIGGERED ==========");
        $this->log("Project ID: $project_id");
        $this->log("Record: $record");
        $this->log("Instrument: $instrument");
        $this->log("Event ID: $event_id");

        $configurations = $this->getProjectSetting('configs');
        $this->log("Number of configurations: " . count($configurations));

        foreach ($configurations as $index => $config) {
            $this->log("--- Processing configuration $index ---");
            $this->processConfiguration($index, $record, $instrument, $event_id);
        }
        $this->log("========== HOOK COMPLETE ==========\n");
    }

    /**
     * Process a single configuration
     */
    private function processConfiguration($index, $record, $instrument, $event_id)
    {
        // Load configuration
        $config = $this->loadConfiguration($index);
        $this->log("Loaded config: " . json_encode($config));

        // Validate configuration
        if (!$this->isConfigurationValid($config)) {
            $this->log("Configuration invalid - missing required fields for shuffle type");
            return;
        }

        // Check if current event matches configuration
        if ($config['shuffle_event'] !== null && $config['shuffle_event'] !== $event_id) {
            $this->log("Event mismatch: shuffle_event={$config['shuffle_event']}, current event=$event_id");
            return;
        }

        $this->log("Event OK - shuffle_event is null or matches");

        // Route to appropriate shuffle method
        $this->log("Shuffle type: " . ($config['shuffle_type'] ?: 'random (default)'));
        
        if ($config['shuffle_type'] === 'field') {
            $this->log("Routing to: handleFieldBasedShuffle");
            $this->handleFieldBasedShuffle($config, $record, $instrument, $event_id);
        } else {
            $this->log("Routing to: handleRandomShuffle");
            $this->handleRandomShuffle($config, $record, $instrument, $event_id);
        }
    }

    /**
     * Load all settings for a configuration
     */
    private function loadConfiguration($index)
    {
        $shuffle_event = $this->getProjectSetting('shuffle-event')[$index] ?? null;
        $shuffle_type = $this->getProjectSetting('shuffle-type')[$index] ?? 'random';
        $entry_survey = $this->getProjectSetting('entry-survey')[$index] ?? null;
        $descriptive_text = $this->getProjectSetting('descriptive-text')[$index] ?? null;
        $shuffle_instruments = $this->getProjectSetting('shuffle-instruments')[$index] ?? [];
        $order_field = $this->getProjectSetting('order-field')[$index] ?? null;
        $order_field_event = $this->getProjectSetting('order-field-event')[$index] ?? null;
        $shuffle_number = $this->getProjectSetting('shuffle-number')[$index] ?? null;
        $exit_survey = $this->getProjectSetting('exit-survey')[$index] ?? null;
        $sequence_field = $this->getProjectSetting('sequence-field')[$index] ?? null;
        $sequence_field_event = $this->getProjectSetting('sequence-field-event')[$index] ?? null;

        $this->log("LoadConfiguration[$index]:");
        $this->log("  shuffle_event: $shuffle_event");
        $this->log("  shuffle_type: $shuffle_type");
        $this->log("  entry_survey: $entry_survey");
        $this->log("  order_field: $order_field");
        $this->log("  exit_survey: $exit_survey");

        return [
            'shuffle_event' => $shuffle_event,
            'shuffle_type' => $shuffle_type,
            'entry_survey' => $entry_survey,
            'descriptive_text' => $descriptive_text,
            'shuffle_instruments' => $shuffle_instruments,
            'order_field' => $order_field,
            'order_field_event' => $order_field_event,
            'shuffle_number' => $shuffle_number,
            'exit_survey' => $exit_survey,
            'sequence_field' => $sequence_field,
            'sequence_field_event' => $sequence_field_event,
        ];
    }

    /**
     * Validate required configuration fields
     */
    private function isConfigurationValid($config)
    {
        // Pour "From field", we need  entry_survey and order_field
        if ($config['shuffle_type'] === 'field') {
            $valid = !empty($config['entry_survey']) && !empty($config['order_field']);
            $this->log("From Field Validation: entry_survey=" . ($config['entry_survey'] ?: 'EMPTY') . 
                       ", order_field=" . ($config['order_field'] ?: 'EMPTY') . " => " . ($valid ? "VALID" : "INVALID"));
            return $valid;
        }
        
        // Pour "Random", we need entry_survey and  shuffle_instruments
        $valid = !empty($config['entry_survey']) && !empty($config['shuffle_instruments']);
        $this->log("Random Validation: entry_survey=" . ($config['entry_survey'] ?: 'EMPTY') . 
                   ", shuffle_instruments=" . (count($config['shuffle_instruments']) . " items") . " => " . ($valid ? "VALID" : "INVALID"));
        return $valid;
    }

    /**
     * Handle random shuffle logic
     */
    private function handleRandomShuffle($config, $record, $instrument, $event_id)
    {
        $this->log(">>> handleRandomShuffle START");

        // Determine trigger instruments
        $trigger_instruments = array_merge(
            $config['shuffle_instruments'],
            [$config['entry_survey'] ?? $this->getFirstInstrument()]
        );

        $this->log("Trigger instruments: " . json_encode($trigger_instruments));

        // Check if current instrument should trigger shuffle
        if (!in_array($instrument, $trigger_instruments)) {
            $this->log("Current instrument '$instrument' NOT in trigger list - STOPPING");
            return;
        }

        $this->log("Current instrument '$instrument' IS in trigger list - CONTINUING");

        // Get completed and remaining instruments
        $remaining = $this->getRemainingInstruments($config['shuffle_instruments'], $record, $event_id);
        $this->log("Remaining instruments: " . json_encode($remaining));

        $shuffle_number = $this->getShuffle_number($config['shuffle_number'], $config['shuffle_instruments']);
        $this->log("Shuffle number to display: $shuffle_number");

        $completed_count = count($config['shuffle_instruments']) - count($remaining);
        $this->log("Completed count: $completed_count / " . count($config['shuffle_instruments']));

        if ($completed_count < $shuffle_number) {
            // Display next survey
            $this->log("Not all surveys completed - redirecting to next survey");
            $this->redirectToNextSurvey($remaining, $record, $event_id, $config);
        } else {
            // All surveys completed, go to exit survey if configured
            $this->log("All surveys completed!");
            if ($config['exit_survey']) {
                $this->log("Redirecting to exit survey: " . $config['exit_survey']);
                $this->redirectToSurvey($config['exit_survey'], $record, $event_id);
            } else {
                $this->log("No exit survey configured");
            }
        }
        $this->log(">>> handleRandomShuffle END\n");
    }

    /**
     * Handle field-based shuffle logic
     */
    private function handleFieldBasedShuffle($config, $record, $instrument, $event_id)
    {
        $this->log(">>> handleFieldBasedShuffle START");

        $field_event = $config['order_field_event'] ?? $event_id;
        $this->log("Field event: $field_event");
        $this->log("Order field name: " . $config['order_field']);

        $order = $this->getOrderFromField($config['order_field'], $record, $field_event);
        $this->log("Order array retrieved: " . json_encode($order));
        $this->log("Entry survey: " . $config['entry_survey']);
        $this->log("Current instrument: $instrument");

        if (empty($order)) {
            $this->log("Order is EMPTY - STOPPING");
            return;
        }

        $this->log("Checking conditions...");

        
        if ($instrument === $config['entry_survey']) {
            $this->log("✓ Current instrument IS entry survey");
            $this->log("First instrument in order: " . $order[0]);
            $this->redirectToSurvey($order[0], $record, $event_id);
            return;
        }

        
        $key = array_search($instrument, $order);

        if ($key !== false) {
            
            if ($key < count($order) - 1) {
                $this->log("✓ Current instrument IS in order list (position $key)");
                $this->log("Next instrument in order: " . $order[$key + 1]);
                $this->redirectToSurvey($order[$key + 1], $record, $event_id);
            } 
            
            elseif ($config['exit_survey']) {
                $this->log("✓ Current instrument IS last in order");
                $this->log("Redirecting to exit survey: " . $config['exit_survey']);
                $this->redirectToSurvey($config['exit_survey'], $record, $event_id);
            }
        } else {
            
            $this->log("✗ Current instrument is not entry survey and not in order list - letting REDCap handle flow.");
        }

        $this->log(">>> handleFieldBasedShuffle END\n");
    }

    /**
     * Get remaining (not completed) instruments
     */
    private function getRemainingInstruments($instruments, $record, $event_id)
    {
        $this->log("getRemainingInstruments - checking status of: " . json_encode($instruments));

        $remaining = array_filter($instruments, function ($instrument) use ($record, $event_id) {
            $is_complete = $this->isInstrumentComplete($instrument, $record, $event_id);
            $status = $is_complete ? "COMPLETE" : "INCOMPLETE";
            $this->log("  $instrument: $status");
            return !$is_complete;
        });

        return $remaining;
    }

    /**
     * Check if an instrument is completed
     */
    private function isInstrumentComplete($instrument, $record, $event_id)
    {
        try {
            $data = REDCap::getData('array', $record, $instrument . '_complete', $event_id);
            $complete_value = $data[$record][$event_id][$instrument . '_complete'] ?? null;
            $this->log("  isInstrumentComplete($instrument): value=$complete_value");
            return $complete_value == 2;
        } catch (\Exception $e) {
            $this->log("  ERROR checking completion for $instrument: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get shuffle number to use
     */
    private function getShuffle_number($shuffle_number, $instruments)
    {
        $result = (is_numeric($shuffle_number) && $shuffle_number > 0) ? $shuffle_number : count($instruments);
        $this->log("getShuffle_number: input=$shuffle_number, result=$result, total_instruments=" . count($instruments));
        return $result;
    }

    /**
     * Get order array from field
     */
    private function getOrderFromField($field, $record, $event_id)
    {
        $this->log("getOrderFromField START - field='$field', record='$record', event='$event_id'");

        try {
            $data = REDCap::getData('array', $record, $field, $event_id);
            $this->log("RAW data from REDCap: " . json_encode($data));

            $value = $data[$record][$event_id][$field] ?? '';
            $this->log("Field value (raw): '$value'");
            $this->log("Field value length: " . strlen($value));

            if (empty($value)) {
                $this->log("Field is EMPTY");
                return [];
            }

            
            $order = array_map('trim', explode(',', $value));
            $this->log("Parsed order list: " . json_encode($order));

            return $order;
        } catch (\Exception $e) {
            $this->log("ERROR in getOrderFromField: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Redirect to next survey in random shuffle
     */
    private function redirectToNextSurvey($remaining, $record, $event_id, $config)
    {
        $this->log("redirectToNextSurvey START");

        $remaining = array_values($remaining); // Re-index array
        $this->log("Remaining after re-index: " . json_encode($remaining));

        shuffle($remaining);
        $this->log("Remaining after shuffle: " . json_encode($remaining));

        $next_survey = $remaining[0];
        $this->log("Next survey selected: $next_survey");

        $this->storeSequence($next_survey, $record, $event_id, $config);
        $this->redirectToSurvey($next_survey, $record, $event_id);
    }

    /**
     * Redirect user to a specific survey
     */
    private function redirectToSurvey($survey, $record, $event_id)
    {
        $this->log("redirectToSurvey: survey='$survey', record='$record', event='$event_id'");

        try {
            $link = REDCap::getSurveyLink($record, $survey, $event_id);
            $this->log("Survey link generated: $link");
            $this->log("REDIRECTING TO: $link");

            header('Location: ' . $link);
            $this->exitAfterHook();
        } catch (\Exception $e) {
            $this->log("ERROR generating survey link: " . $e->getMessage());
        }
    }

    /**
     * Store survey sequence in field if configured
     */
    private function storeSequence($survey, $record, $event_id, $config)
    {
        $this->log("storeSequence START - survey='$survey'");

        if (!$config['sequence_field']) {
            $this->log("No sequence field configured - SKIPPING");
            return;
        }

        $field_event = $config['sequence_field_event'] ?? $event_id;
        $this->log("Sequence field: " . $config['sequence_field'] . ", event: $field_event");

        $current = $this->getOrderFromField($config['sequence_field'], $record, $field_event);
        $this->log("Current sequence: " . json_encode($current));

        $current[] = $survey;
        $this->log("New sequence: " . json_encode($current));

        $data = [
            $record => [
                $field_event => [
                    $config['sequence_field'] => implode(', ', $current)
                ]
            ]
        ];

        $this->log("Saving data: " . json_encode($data));
        REDCap::saveData('array', $data);
        $this->log("Sequence saved successfully");
    }

    /**
     * Get the first instrument name
     */
    private function getFirstInstrument()
    {
        $first = array_key_first(REDCap::getInstrumentNames());
        $this->log("First instrument: $first");
        return $first;
    }

    /**
     * Logging function
     */
    private function log($message)
    {
        if ($this->debug) {
            $timestamp = date('Y-m-d H:i:s');
            $log_message = "[$timestamp] [SurveyShuffle] $message";
            error_log($log_message);
        }
    }
}
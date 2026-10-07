<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\NinjaForms;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Completes a halted submission once ifthenpay confirms payment.
 *
 * Ninja Forms' own resume mechanism needs the customer's PHP session to
 * still be alive, which doesn't hold for an offline method like Multibanco
 * that can get paid days later. So once payment is confirmed, I replay the
 * form's remaining actions myself using the `$data` snapshot from halt time.
 */
class ResumeController
{
    /**
     * @param array<string, mixed> $data Snapshot captured by IfthenpayGateway before halting.
     * @return array<string, mixed> The updated `$data`, after every remaining action has run.
     */
    public function run_remaining_actions(int $form_id, array $data): array
    {
        if (! function_exists('Ninja_Forms')) {
            return $data;
        }

        $processed = $data['processed_actions'] ?? [];

        $actions = Ninja_Forms()->form($form_id)->get_actions();
        $queue   = [];

        foreach ($actions as $action) {
            $queue[] = [
                'id'       => $action->get_id(),
                'settings' => $action->get_settings(),
            ];
        }

        usort($queue, [$this, 'compare_by_timing_then_priority']);

        foreach ($queue as $action) {
            if (in_array($action['id'], $processed, true)) {
                continue;
            }

            $settings = $action['settings'];
            $settings['id'] = $action['id'];

            if (empty($settings['active'])) {
                $processed[] = $action['id'];
                continue;
            }

            $type = $settings['type'] ?? '';

            if (! is_string($type) || ! isset(Ninja_Forms()->actions[$type])) {
                $processed[] = $action['id'];
                continue;
            }

            $action_class = Ninja_Forms()->actions[$type];

            if (! is_object($action_class) || ! method_exists($action_class, 'process')) {
                $processed[] = $action['id'];
                continue;
            }

            $result = $action_class->process($settings, $form_id, $data);

            if (is_array($result)) {
                $data = $result;
            }

            $processed[] = $action['id'];

            if (! empty($data['halt'])) {
                break;
            }
        }

        $data['processed_actions'] = $processed;

        return $data;
    }

    /**
     * @param array{settings: array<string, mixed>} $a
     * @param array{settings: array<string, mixed>} $b
     */
    private function compare_by_timing_then_priority(array $a, array $b): int
    {
        [$timing_a, $priority_a] = $this->timing_and_priority($a['settings']['type'] ?? '');
        [$timing_b, $priority_b] = $this->timing_and_priority($b['settings']['type'] ?? '');

        if ($timing_a === $timing_b) {
            return $priority_a <=> $priority_b;
        }

        return $timing_a <=> $timing_b;
    }

    /**
     * @return array{0: string, 1: int|string}
     */
    private function timing_and_priority(string $type): array
    {
        if ('' === $type || ! function_exists('Ninja_Forms') || ! isset(Ninja_Forms()->actions[$type])) {
            return ['', 0];
        }

        $action = Ninja_Forms()->actions[$type];

        return [(string) $action->get_timing(), $action->get_priority()];
    }
}

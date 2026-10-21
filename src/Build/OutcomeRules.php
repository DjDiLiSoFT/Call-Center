<?php

namespace CallStats\Build;

/**
 * Per-operator outcome of one call (spec 5.3). Fixed algorithm, order matters.
 */
class OutcomeRules
{
    /**
     * @param string[] $attemptKinds subset of {'rang','canceled','offline'}
     */
    public static function decide(array $attemptKinds, bool $answeredByThis, bool $answeredByOther): string
    {
        $rang = in_array('rang', $attemptKinds, true);
        $canceled = in_array('canceled', $attemptKinds, true);

        if ($answeredByThis) {
            return 'answered';
        }
        if (($rang || $canceled) && $answeredByOther) {
            return 'other_answered';
        }
        if ($rang && !$answeredByOther) {
            return 'missed';
        }
        if ($canceled && !$answeredByOther) {
            return 'caller_hangup';
        }
        return 'offline';
    }
}

<?php

/** Add a brief general SpO2 explanation when a private-reading answer asks for one. */
function medconnect_append_spo2_explanation(string $question, string $reply): string
{
    if (preg_match('/\b(what does|meaning of|explain)\b.{0,50}\b(spo2|oxygen)\b/i', $question)
        && stripos($reply, 'SpO2 is an estimate') === false) {
        $reply .= ' SpO2 is an estimate of the percentage of oxygen-saturated hemoglobin in the blood. This general explanation is not a diagnosis.';
    }
    return $reply;
}

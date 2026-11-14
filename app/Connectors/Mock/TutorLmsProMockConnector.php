<?php

namespace App\Connectors\Mock;

class TutorLmsProMockConnector extends AbstractMockConnector
{
    public function key(): string
    {
        return 'tutor_lms_pro';
    }

    public function name(): string
    {
        return 'Tutor LMS Pro';
    }

    protected function fixtureNamespace(): string
    {
        return 'tutor_lms_pro';
    }
}

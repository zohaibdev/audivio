<?php

namespace App\Enums;

enum AiProviderName: string
{
    case Claude = 'claude';
    case OpenAi = 'openai';
    case Gemini = 'gemini';
}

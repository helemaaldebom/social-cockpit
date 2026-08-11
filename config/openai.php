<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API Key and organization. This will be
    | used to authenticate with the OpenAI API - you can find your API key
    | and organization on your OpenAI dashboard, at https://openai.com.
    */

    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Project
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API project. This is used optionally in
    | situations where you are using a legacy user API key and need association
    | with a project. This is not required for the newer API keys.
    */
    'project' => env('OPENAI_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI Base URL
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API base URL used to make requests. This
    | is needed if using a custom API endpoint. Defaults to: api.openai.com/v1
    */
    'base_uri' => env('OPENAI_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout may be used to specify the maximum number of seconds to wait
    | for a response. By default, the client will time out after 30 seconds.
    */

    'request_timeout' => env('OPENAI_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Model & tokenbeheer (Social Cockpit)
    |--------------------------------------------------------------------------
    |
    | model: het chatmodel voor tekstgeneratie en -verfijning. gpt-4o is de
    | kwaliteitsdefault; gpt-4o-mini is ~15x goedkoper en een prima optie om
    | te testen via OPENAI_MODEL zonder code-wijziging.
    |
    | max_output_tokens: bovengrens per antwoord. Social posts zijn ~300-500
    | tokens; 600 laat ruimte zonder onbeperkt te betalen voor uitloop.
    |
    | max_examples: aantal few-shot voorbeeldposts per generatie-call. De
    | tone-of-voice prompt bevat zelf al stijlregels en een voorbeeld, dus
    | een handvol extra voorbeelden volstaat.
    */
    'model' => env('OPENAI_MODEL', 'gpt-4o'),
    'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 600),
    'max_examples' => (int) env('OPENAI_MAX_EXAMPLES', 4),

    // Lager = feitelijker, minder verzinnen. 0.4 is bewust conservatief.
    'temperature' => (float) env('OPENAI_TEMPERATURE', 0.4),
];

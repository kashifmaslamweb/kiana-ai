<?php

declare(strict_types=1);

namespace App\Services;

class SongGeneratorService
{
    private const AI_API_URL = 'https://chatbot-y2iq.onrender.com/chatbot';
    private const REQUEST_TIMEOUT = 60;
    
    /**
     * @var array<string, string>
     */
    private array $requiredFields = [
        'musicStyle' => 'Music style',
        'country' => 'Country',
        'prompt' => 'Theme prompt'
    ];

    /**
     * Handle the song generation request
     *
     * @param string $rawInput
     * @return array
     * @throws \JsonException
     */
    public function handleRequest(string $rawInput): array
    {
        $this->validateRequestMethod();
        $data = $this->parseAndValidateInput($rawInput);
        $sanitizedData = $this->sanitizeInput($data);
        $aiPrompt = $this->constructPrompt($sanitizedData);
        return $this->makeApiRequest($aiPrompt);
    }

    /**
     * Validate that only POST requests are allowed
     *
     * @throws \RuntimeException
     */
    private function validateRequestMethod(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new \RuntimeException('Only POST requests are allowed.', 405);
        }
    }

    /**
     * Parse and validate the JSON input
     *
     * @param string $rawInput
     * @return array
     * @throws \JsonException
     */
    private function parseAndValidateInput(string $rawInput): array
    {
        $data = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
        
        foreach ($this->requiredFields as $field => $label) {
            if (empty($data[$field])) {
                throw new \RuntimeException("Missing required field: {$label}", 400);
            }
        }

        return $data;
    }

    /**
     * Sanitize user input
     *
     * @param array $data
     * @return array
     */
    private function sanitizeInput(array $data): array
    {
        $sanitized = [];
        foreach ($this->requiredFields as $field => $_) {
            $sanitized[$field] = htmlspecialchars(
                trim($data[$field]),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }
        return $sanitized;
    }

    /**
     * Construct the AI prompt
     *
     * @param array $data
     * @return string
     */
    private function constructPrompt(array $data): string
    {
        return sprintf(
            "You are an AI songwriter specialized in generating song lyrics. " .
            "Write a song in the %s genre, inspired by %s. " .
            "The theme of the song is: %s. " .
            "Structure the song into sections: [Verse 1], [Chorus], [Verse 2], and [Bridge] if applicable. " .
            "At the end of each section, include the corresponding solfa notes (e.g., d, r, m, f, s, l, t, d) to guide musicians on how to sing it. " .
            "Ensure the lyrics are creative, engaging, and reflective of the specified theme. " .
            "Do not use any special formatting characters like asterisks (*), bold, or italics in the output.",
            $data['musicStyle'],
            $data['country'],
            $data['prompt']
        );
    }

    /**
     * Make the API request to the AI service
     *
     * @param string $prompt
     * @return array
     * @throws \RuntimeException
     */
    private function makeApiRequest(string $prompt): array
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new \RuntimeException('Failed to initialize cURL', 500);
        }

        $payload = json_encode([
            'prompt' => $prompt,
            'temperature' => 2.0,
            'n' => 1,
            'stop' => null
        ], JSON_THROW_ON_ERROR);

        curl_setopt_array($curl, [
            CURLOPT_URL => self::AI_API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ]
        ]);

        $response = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new \RuntimeException('cURL Error: ' . $curlError, 500);
        }

        return $this->processApiResponse($response, $httpStatus);
    }

    /**
     * Process the API response
     *
     * @param string $response
     * @param int $httpStatus
     * @return array
     * @throws \RuntimeException
     */
    private function processApiResponse(string $response, int $httpStatus): array
    {
        if ($httpStatus !== 200) {
            throw new \RuntimeException('AI API Error: ' . $response, $httpStatus);
        }

        $responseData = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        
        if (isset($responseData['response'])) {
            return ['song' => $responseData['response']];
        }
        
        if (isset($responseData['choices'][0]['text'])) {
            return ['song' => trim($responseData['choices'][0]['text'])];
        }

        throw new \RuntimeException('Unexpected API response structure', 500);
    }
}

// Usage in your endpoint file (e.g., backend.php):
try {
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");

    $service = new SongGeneratorService();
    $result = $service->handleRequest(file_get_contents("php://input"));
    
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}

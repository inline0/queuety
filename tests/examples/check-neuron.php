<?php
/**
 * Execute the documented provider/checkpoint/stream contracts without network I/O.
 *
 * Run composer install --working-dir=tests/examples, then php this file.
 * A supplied autoloader path supports the same pinned source in PHP-floor probes.
 */

declare(strict_types=1);

require $argv[1] ?? __DIR__ . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Queuety\\')) {
        $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function load_example(string $file, string $block): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/docs/examples/' . $file);
    if (!preg_match('/<!-- executable: ' . preg_quote($block, '/') . ' -->\s*```php\n(.*?)\n```/s', $source, $match)) {
        throw new RuntimeException('Executable documentation block missing: ' . $block);
    }
    eval($match[1]);
}

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}

final class RecordedHttp implements NeuronAI\HttpClient\HttpClientInterface
{
    public array $requests = [];

    public function __construct(private readonly array $reply) {}

    public function request(NeuronAI\HttpClient\HttpRequest $request): NeuronAI\HttpClient\HttpResponse
    {
        $this->requests[] = $request;
        return new NeuronAI\HttpClient\HttpResponse(200, json_encode($this->reply, JSON_THROW_ON_ERROR));
    }

    public function stream(NeuronAI\HttpClient\HttpRequest $request): NeuronAI\HttpClient\StreamInterface
    {
        throw new RuntimeException('Unexpected real HTTP streaming call.');
    }
}

load_example('neuron-planner-executor.md', 'provider-factory');
load_example('neuron-memory-review.md', 'conversation-turn');
load_example('neuron-streaming-step.md', 'provider-stream');

$fixtures = [
    'anthropic' => ['content' => [['type' => 'text', 'text' => 'summary']], 'role' => 'assistant', 'stop_reason' => 'end_turn'],
    'gemini' => ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'summary']]], 'finishReason' => 'STOP']]],
    'openai' => ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'summary', 'annotations' => []]]]]],
];
foreach ($fixtures as $name => $reply) {
    $http = new RecordedHttp($reply);
    $provider = App\Neuron\ProviderFactory::make($name, 'test-key', 'test-model', $http);
    $checkpoint = App\Neuron\ConversationTurn::run($provider, ['version' => 1, 'thread_id' => 'test-thread', 'messages' => []], 'Research.');
    check(count($http->requests) === 1, $name . ' performs one injected HTTP request');
    check($checkpoint['reply'] === 'summary' && $checkpoint['status'] === 'completed', $name . ' reads ProviderResponse::message()');
    check($checkpoint['thread_id'] === 'test-thread' && count($checkpoint['messages']) === 2, $name . ' serializes the conversation checkpoint');
}

$tool_http = new RecordedHttp([
    'role' => 'assistant',
    'stop_reason' => 'tool_use',
    'content' => [['type' => 'tool_use', 'id' => 'stable-call-42', 'name' => 'read_file', 'input' => (object) []]],
]);
$tool = new class extends NeuronAI\Tools\Tool {
    protected string $name = 'read_file';
    public static int $executions = 0;
    public function __invoke(): string { ++self::$executions; return 'content'; }
};
$provider = App\Neuron\ProviderFactory::make('anthropic', 'test-key', 'test-model', $tool_http)->setTools([$tool]);
$checkpoint = App\Neuron\ConversationTurn::run($provider, ['version' => 1, 'thread_id' => 'review-thread', 'messages' => []], 'Read the file.');
check($checkpoint['status'] === 'waiting_for_tool' && $tool::$executions === 0, 'provider returns tool calls without executing an agent loop');
$serialized = json_encode($checkpoint, JSON_THROW_ON_ERROR);
$restored = json_decode($serialized, true, flags: JSON_THROW_ON_ERROR);
$decoder = new NeuronAI\Chat\Messages\MessageDeserializer();
$call_message = $decoder->deserialize($restored['messages'][1]);
$call = $call_message->getToolCalls()[0];
check($call->getCallId() === 'stable-call-42', 'JSON restore retains provider tool-call identity');
$call->setResult('Application-authorized result');
$restored['messages'][] = json_decode(json_encode(new NeuronAI\Chat\Messages\ToolResultMessage([$call]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
$reply_http = new RecordedHttp($fixtures['anthropic']);
$provider = App\Neuron\ProviderFactory::make('anthropic', 'test-key', 'test-model', $reply_http)->setTools([$tool]);
$resumed = App\Neuron\ConversationTurn::run($provider, $restored, 'Use the approved result.');
check(count($reply_http->requests) === 1 && $resumed['thread_id'] === 'review-thread', 'restored review context takes one provider turn');
check($reply_http->requests[0]->body['messages'][2]['content'][0]['tool_use_id'] === 'stable-call-42', 'restored tool result maps to the original provider call');

$provider = new NeuronAI\Testing\FakeAIProvider(new NeuronAI\Chat\Messages\AssistantMessage('Complete draft'));
$chunks = iterator_to_array(App\Neuron\ProviderStream::text($provider, 'Write a memo.'));
check(App\Neuron\ProviderStream::complete($chunks)['draft_markdown'] === 'Complete draft', 'stream generator final return produces a completed draft');
try {
    App\Neuron\ProviderStream::complete([['type' => 'text', 'text' => 'Partial']]);
    throw new RuntimeException('Partial output was incorrectly accepted.');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'no completion marker'), 'partial text never becomes a completed draft');
}
try {
    iterator_to_array(App\Neuron\ProviderStream::text($provider, 'Retry.', [['type' => 'text', 'text' => 'Partial']]));
    throw new RuntimeException('Partial output was incorrectly resumed.');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'explicit new attempt'), 'partial stream requires an explicit fresh attempt');
}

Queuety\ExecutionContext::set_deadline(hrtime(true) + 1_000_000_000);
try {
    App\Neuron\ProviderFactory::make('anthropic', 'test-key', 'test-model', new RecordedHttp($fixtures['anthropic']));
    throw new RuntimeException('Exhausted action budget was accepted.');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'No provider budget'), 'remaining action budget is checked before provider construction');
} finally {
    Queuety\ExecutionContext::clear();
}
check(!defined('ABSPATH') && !defined('DB_HOST') && !function_exists('add_action'), 'documented integration runs without WordPress');

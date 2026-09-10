<?php

declare(strict_types=1);

use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\OutputFormat\StructPatchOutput;
use Phore\AiHarness\Patch\{JsonValue, PatchApplyOptions, PatchLimitException, PatchValidationException};
use Phore\AiHarness\ToolType\WebAccessTool;
use PHPUnit\Framework\TestCase;

final class StructPatchOutputDto
{
    public function __construct(public string $answer) {}
}

final class StructPatchOutputTest extends TestCase
{
    public function testStrictSchemaAndLosslessWireDecoding(): void
    {
        $format = new StructPatchOutput();
        $schema = $format->jsonSchema();
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['unsupported','operations'], $schema['required']);
        self::assertSame(['op','path','value_json','from'], $schema['properties']['operations']['items']['required']);
        $patch = $format->parse('{"unsupported":false,"operations":[{"op":"add","path":"/x","value_json":"{\"emptyObject\":{},\"list\":[],\"null\":null}","from":null}]}');
        self::assertInstanceOf(stdClass::class, $patch->operations[0]->value()->emptyObject);
        self::assertSame([], $patch->operations[0]->value()->list);
        self::assertNull($patch->operations[0]->value()->null);
        $null = $format->parse('{"unsupported":false,"operations":[{"op":"replace","path":"/x","value_json":"null","from":null}]}');
        self::assertNull($null->operations[0]->value());
    }

    public function testMalformedAndUnsupportedResponsesFailClosed(): void
    {
        foreach ([
            'not json', '[]', '{}', '{"unsupported":false,"operations":[],"extra":1}',
            '{"unsupported":true,"operations":[]}',
            '{"unsupported":true,"operations":[{}]}',
            '{"unsupported":false,"operations":[{"op":"add","path":"/a","value_json":null,"from":null}]}',
            '{"unsupported":false,"operations":[{"op":"add","path":"/a","value_json":"bad json","from":null}]}',
            '{"unsupported":false,"operations":[{"op":"remove","path":"/a","value_json":"1","from":null}]}',
            '{"unsupported":false,"operations":[{"op":"move","path":"/a","value_json":null,"from":"/b"}]}',
        ] as $output) {
            try {
                (new StructPatchOutput())->parse($output);
                self::fail('Expected invalid response');
            } catch (PatchValidationException $exception) {
                self::assertNotSame('', $exception->errorCode);
            }
        }
        $this->expectException(PatchLimitException::class);
        (new StructPatchOutput(new PatchApplyOptions(maxPatchBytes: 2)))->parse('{"unsupported":false,"operations":[]}');
    }

    public function testHelperRejectsToolsAndExperimentalModesBeforeNetworkAccess(): void
    {
        foreach ([['mode'=>'auto'], ['addressing'=>'unknown']] as $options) {
            try {
                phore_ai_edit_struct('edit', new StructPatchOutputDto('before'), $options);
                self::fail('Expected invalid options');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        phore_ai_edit_struct(new WebAccessTool(), new StructPatchOutputDto('before'));
    }

    public function testHelperSendsOneStrictRequestAndHydratesResponse(): void
    {
        if (!function_exists('proc_open') || !function_exists('stream_socket_server') || extension_loaded('wasm_memory_storage')) {
            self::markTestSkipped('Local HTTP integration requires process and socket support.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $directory = sys_get_temp_dir() . '/struct-patch-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $router = $directory . '/router.php';
        $capture = $directory . '/request.json';
        $reply = JsonValue::encode(['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>
            '{"unsupported":false,"operations":[{"op":"replace","path":"/answer","value_json":"\"after\"","from":null}]}'
        ]]]]]);
        file_put_contents($router, '<?php file_put_contents(' . var_export($capture,true) . ', file_get_contents("php://input") . "\n", FILE_APPEND); header("Content-Type: application/json"); echo ' . var_export($reply,true) . ';');
        $process = proc_open([PHP_BINARY,'-S',$address,$router], [0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']], $pipes);
        self::assertIsResource($process);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
                if ($probe !== false) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready, 'Local test server did not start');
            $target = new StructPatchOutputDto('before');
            $result = phore_ai_edit_struct('Set answer to after', $target, [
                'client'=>new OpenAiClient('test-key', baseUrl:'http://' . $address . '/v1'), 'return_patch'=>true,
            ]);
            self::assertSame('after', $result->value->answer);
            self::assertSame('before', $target->answer);
            $requests = file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertCount(1, $requests);
            $request = json_decode($requests[0], true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($request['text']['format']['strict']);
            self::assertSame('StructPatch', $request['text']['format']['name']);
            self::assertArrayNotHasKey('tools', $request);
            self::assertStringContainsString('sequentially', $request['instructions']);
            self::assertStringContainsString('target_schema', json_encode($request['input']));
        } finally {
            fclose($pipes[0]);
            proc_terminate($process);
            proc_close($process);
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}

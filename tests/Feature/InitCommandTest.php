<?php

test('init project command', function () {
    $this->artisan('init', ['project' => 'foo'])->assertExitCode(1);

    // Assert that a command was called
    $this->assertCommandCalled('init', ['project' => 'foo']);
});

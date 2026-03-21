<?php
test('should open homepage and it works', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});

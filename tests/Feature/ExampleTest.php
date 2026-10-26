<?php

test('the home page sends visitors to the dashboard', function () {
    $this->get(route('home'))->assertRedirect('/dashboard');
});

<?php

use Illuminate\Support\Facades\Route;

Route::post('git-deploy', 'ArWars\GitDeploy\Http\GitDeployController@gitHook');

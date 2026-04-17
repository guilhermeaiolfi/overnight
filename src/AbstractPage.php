<?php

declare(strict_types=1);

namespace ON;

abstract class AbstractPage implements PageInterface
{
	public function isSecure()
	{
		return false;
	}

	public function defaultIndex()
	{
		return 'Success';
	}

	public function defaultHandleError()
	{
		return 'Error';
	}

	public function defaultValidate()
	{
		return true;
	}

	public function defaultCheckPermissions()
	{
		return true;
	}

	public function defaultIsSecure()
	{
		return false;
	}
}

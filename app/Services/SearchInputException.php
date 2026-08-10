<?php

namespace App\Services;

/**
 * The search term itself cannot be run as asked — a first-name-only search, an
 * SSN last-four with no surname. The message is written for the user and is
 * safe to show: it explains the index, not the schema.
 */
class SearchInputException extends \RuntimeException {}

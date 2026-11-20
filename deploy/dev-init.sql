-- Runs once, when the dev database volume is first created.
-- A second database for the test suite, so tests never touch development data.
CREATE DATABASE application_test_testing;

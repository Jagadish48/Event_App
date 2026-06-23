<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function testCleanInputRemovesWhitespace()
    {
        $input = "  hello world  ";
        $expected = "hello world";
        
        $this->assertEquals($expected, clean_input($input));
    }

    public function testCleanInputEscapesHtml()
    {
        $input = "<script>alert('xss')</script>";
        $expected = "&lt;script&gt;alert('xss')&lt;/script&gt;";
        
        $this->assertEquals($expected, clean_input($input));
    }

    public function testFormatCurrency()
    {
        $this->assertEquals("Rs. 100.00", format_currency(100));
        $this->assertEquals("Rs. 1,234.50", format_currency(1234.5));
    }
}

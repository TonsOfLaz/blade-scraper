<?php
namespace TonsOfLaz\BladeScraper\Test;

use TonsOfLaz\BladeScraper\BladeScraper;

class BasicTest extends \Orchestra\Testbench\TestCase
{

    protected function getPackageProviders($app)
    {
        return ['TonsOfLaz\BladeScraper\BladeScraperServiceProvider'];
    }

    /*
        Get the full data from a Bill Intro page
    */
    public function test_it_scrapes_a_whole_bill_intro_page()
    {
        $bs = new BladeScraper;
        $html = file_get_contents('./tests/html/BillIntro.html');

        $data = $bs->extract('./tests/views/bill-intro.blade.php', $html);
        $bills = $data['bills'];
        $this->assertTrue($bills->count() == 12);
        $this->assertTrue($bills->count() == $data['total_bills']);
    }
    
    /*
        Nested foreach, if, etc. Complex page
    */
        
    public function test_it_scrapes_a_complex_page()
    {
        $bs = new BladeScraper;
        $html = file_get_contents('./tests/html/complex.html');

        $data = $bs->extract('./tests/views/complex.blade.php', $html);

        $bills = $data['bills'];
        $this->assertTrue($bills->count() == 2);
        $this->assertTrue($bills[0]->cosponsors->count() == 4);
    }


    /*
        Allow users to put in just a section of the code from the page.
        It will ignore all code before and after that section.
    */
     
    public function test_it_ignores_the_beginning_and_end_of_the_html()
    {
        $bs = new BladeScraper;
        $html = file_get_contents('./tests/html/BillIntro.html');

        $data = $bs->extract('./tests/views/partial.blade.php', $html);
        //dd($data);
        $partial = $data['partial'];
        $this->assertTrue($partial == 'Total Bills: 12');
    }


    /*
        It can handle a url instead of an HTML block
    */
    public function test_it_is_passed_a_url()
    {
        $bs = new BladeScraper;
        $data = $bs->extract('./tests/views/committees-url.blade.php', 'http://status.rilin.state.ri.us/Committees.aspx');
        //dd($data);
        $this->assertTrue(is_array($data));
    }

    

    
}
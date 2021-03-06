<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * Selenium TestCase for table related tests
 *
 * @package    PhpMyAdmin-test
 * @subpackage Selenium
 */

require_once 'TestBase.php';

/**
 * PmaSeleniumDbEventsTest class
 *
 * @package    PhpMyAdmin-test
 * @subpackage Selenium
 * @group      selenium
 */
class PMA_SeleniumDbEventsTest extends PMA_SeleniumBase
{
    /**
     * Setup the browser environment to run the selenium test case
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();
        $this->dbQuery(
            "CREATE TABLE `test_table` ("
            . " `id` int(11) NOT NULL AUTO_INCREMENT,"
            . " `val` int(11) NOT NULL,"
            . " PRIMARY KEY (`id`)"
            . ")"
        );
        $this->dbQuery(
            "INSERT INTO `test_table` (val) VALUES (2);"
        );
        $this->dbQuery(
            "SET GLOBAL event_scheduler=\"ON\""
        );
    }

    /**
     * setUp function that can use the selenium session (called before each test)
     *
     * @return void
     */
    public function setUpPage()
    {
        parent::setUpPage();

        $this->login();
        $this->navigateDatabase($this->database_name);

        // Let the Database page load
        $this->waitForElementNotPresent('byId', 'ajax_message_num_1');
        $this->expandMore();
    }

    /**
     * Tear Down function for test cases
     *
     * @return void
     */
    public function tearDown()
    {
        if (isset($this->_mysqli)) {
            $this->dbQuery("SET GLOBAL event_scheduler=\"OFF\"");
        }
        parent::tearDown();
    }

    /**
     * Creates procedure for tests
     *
     * @return void
     */
    private function _eventSQL()
    {
        $start = date('Y-m-d H:i:s', strtotime('-1 day'));
        $end = date('Y-m-d H:i:s', strtotime('+1 day'));

        $this->dbQuery(
            "CREATE EVENT `test_event` ON SCHEDULE EVERY 1 MINUTE_SECOND STARTS "
            . "'$start' ENDS '$end' ON COMPLETION NOT PRESERVE ENABLE "
            . "DO UPDATE `" . $this->database_name
            . "`.`test_table` SET val = val + 1"
        );
    }

    /**
     * Create an event
     *
     * @return void
     *
     * @group large
     */
    public function testAddEvent()
    {
        $ele = $this->waitForElement("byPartialLinkText", "Events");
        $ele->click();

        $ele = $this->waitForElement("byPartialLinkText", "Add event");
        $ele->click();

        $this->waitForElement("byClassName", "rte_form");

        $this->select($this->byName("item_type"))
            ->selectOptionByLabel("RECURRING");

        $this->byName("item_name")->value("test_event");
        $this->select($this->byName("item_interval_field"))
            ->selectOptionByLabel("MINUTE_SECOND");

        $this->byName("item_starts")->click();
        $this->keys(date('Y-m-d', strtotime('-1 day')));

        $this->byName("item_ends")->click();
        $this->keys(date('Y-m-d', strtotime('+1 day')));

        // Dynamic wait, retry if complete text not typed
        $this->waitUntil(function () {
            $startDate = date('Y-m-d', strtotime('-1 day'));
            if ($this->byName('item_starts')->value() === $startDate) {
                return true;
            }

            $this->byName("item_starts")->clear();
            $this->byName("item_starts")->click();
            $this->keys($startDate);
            return null;
        }, 5000);
        $this->waitUntil(function () {
            $endDate = date('Y-m-d', strtotime('+1 day'));
            if ($this->byName('item_ends')->value() === $endDate) {
                return true;
            }

            $this->byName("item_ends")->clear();
            $this->byName("item_ends")->click();
            $this->keys($endDate);
            return null;
        }, 5000);

        $ele = $this->waitForElement('byName', "item_interval_value");
        $ele->value('1');

        $proc = "UPDATE " . $this->database_name . ".`test_table` SET val=val+1";
        $this->typeInTextArea($proc, 2);

        $this->byXPath("//button[contains(., 'Go')]")->click();

        $ele = $this->waitForElement(
            "byXPath",
            "//div[@class='success' and contains(., "
            . "'Event `test_event` has been created')]"
        );
        $this->waitForElementNotPresent(
            'byXPath',
            '//div[@id=\'alertLabel\' and not(contains(@style,\'display: none;\'))]'
        );

        // Refresh the page
        $this->url($this->url());

        $this->assertTrue(
            $this->isElementPresent(
                'byXPath',
                "//td[contains(., 'test_event')]"
            )
        );

        $result = $this->dbQuery(
            "SHOW EVENTS WHERE Db='" . $this->database_name
            . "' AND Name='test_event'"
        );
        $this->assertEquals(1, $result->num_rows);

        sleep(2);
        $result = $this->dbQuery(
            "SELECT val FROM `" . $this->database_name . "`.`test_table`"
        );
        $row = $result->fetch_assoc();
        $this->assertGreaterThan(2, $row['val']);
    }

    /**
     * Test for editing events
     *
     * @return void
     *
     * @group large
     */
    public function testEditEvents()
    {
        $this->_eventSQL();
        $ele = $this->waitForElement("byPartialLinkText", "Events");
        $ele->click();

        $this->waitForElement(
            "byXPath",
            "//legend[contains(., 'Events')]"
        );

        $this->byPartialLinkText("Edit")->click();

        $this->waitForElement("byClassName", "rte_form");
        $this->byName("item_interval_value")->clear();
        $this->byName("item_interval_value")->value("2");

        $this->byXPath("//button[contains(., 'Go')]")->click();

        $ele = $this->waitForElement(
            "byXPath",
            "//div[@class='success' and contains(., "
            . "'Event `test_event` has been modified')]"
        );

        sleep(2);
        $result = $this->dbQuery(
            "SELECT val FROM `" . $this->database_name . "`.`test_table`"
        );
        $row = $result->fetch_assoc();
        $this->assertGreaterThan(2, $row['val']);
    }

    /**
     * Test for dropping event
     *
     * @return void
     *
     * @group large
     */
    public function testDropEvent()
    {
        $this->_eventSQL();
        $this->waitForElement("byPartialLinkText", "Events")->click();

        $this->waitForElement(
            "byXPath",
            "//legend[contains(., 'Events')]"
        );

        $this->byPartialLinkText("Drop")->click();
        $this->waitForElement(
            "byClassName", "submitOK"
        )->click();

        $this->waitForElement("byId", "nothing2display");

        sleep(1);
        $result = $this->dbQuery(
            "SHOW EVENTS WHERE Db='" . $this->database_name
            . "' AND Name='test_event'"
        );
        $this->assertEquals(0, $result->num_rows);
    }
}

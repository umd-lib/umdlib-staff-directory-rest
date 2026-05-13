<?php

namespace Drupal\umdlib_staff_directory_rest;

/**
 * Interface for Staff Directory interactions with the Drupal database.
 */
interface DrupalGatewayInterface {

  /**
   * Adds a new Person entry.
   *
   * Person is populated with the staff directory values in the
   * given array.
   *
   * @param array $staff_dir_values
   *   The staff directory values to populate the entry.
   */
  public function addEntry(array $staff_dir_values);

  /**
   * Updates the Person entry with the given directory id.
   *
   * Person is populated with the staff directory values in the array.
   *
   * @param string $directory_id
   *   The UMD directory id for the UmdPerson entry to update.
   * @param array $staff_dir_values
   *   The staff directory values to populate the entry.
   */
  public function updateEntry(string $directory_id, array $staff_dir_values);

  /**
   * Removes (by unpublishing) the Person with the given directory id.
   *
   * @param string $directory_id
   *   The UMD directory id of the Person to remove (unpublish).
   */
  public function removeEntry(string $directory_id);

  /**
   * Republishes the Person with the given directory id.
   *
   * @param string $directory_id
   *   The UMD directory id of the Person to republish.
   */
  public function republishEntry(string $directory_id);

  /**
   * Returns an array of directory ids representing unpublished Persons.
   *
   * @return array
   *   An array of directory ids representing unpublished Persons.
   */
  public function getUnpublishedPersonDirectoryIds();

  /**
   * Returns an associative array of arrays representing UmdTermPersons.
   *
   * Returned array contains all Persons (published and unpublished)
   * in the system, indexed by UMD directory id.
   *
   * Each value array is an associative array containing key/value pairs
   * consistent with the data coming from the incoming JSON file.
   *
   * @return array
   *   An associative array of arrays representing UmdTermPersons.
   */
  public function personsToStaffDirectoryArray();

}

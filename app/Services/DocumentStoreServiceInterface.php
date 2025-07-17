<?php

namespace App\Services;

interface DocumentStoreServiceInterface
{
    /**
     * Get a book by ID
     *
     * @param  string  $id
     * @return array|null
     */
    public function getBook($id);

    /**
     * Create a new book
     *
     * @return string The ID of the created book
     */
    public function createBook(array $data);

    /**
     * Update an existing book
     *
     * @param  string  $id
     * @return bool
     */
    public function updateBook($id, array $data);

    /**
     * Delete a book
     *
     * @param  string  $id
     * @return bool
     */
    public function deleteBook($id);

    /**
     * Get all books
     *
     * @param  int|null  $limit
     * @param  int  $offset
     * @return array
     */
    public function getAllBooks($limit = null, $offset = 0);

    /**
     * Search for books
     *
     * @param  string  $query
     * @param  int|null  $limit
     * @param  int  $offset
     * @return array
     */
    public function searchBooks($query, $limit = null, $offset = 0);

    /**
     * Get books by author
     *
     * @param  string  $author
     * @return array
     */
    public function getBooksByAuthor($author);

    /**
     * Get books by series
     *
     * @param  string  $seriesName
     * @return array
     */
    public function getBooksBySeries($seriesName);

    /**
     * Get books by genre
     *
     * @param  string  $genre
     * @return array
     */
    public function getBooksByGenre($genre);

    /**
     * Get all genres
     *
     * @return array
     */
    public function getAllGenres();

    /**
     * Get all series
     *
     * @return array
     */
    public function getAllSeries();

    /**
     * Get all authors
     *
     * @return array
     */
    public function getAllAuthors();

    /**
     * Add a genre
     *
     * @param  string  $name
     * @return string The ID of the created genre
     */
    public function addGenre($name);

    /**
     * Add a series
     *
     * @param  string  $name
     * @return string The ID of the created series
     */
    public function addSeries($name);

    /**
     * Add an author
     *
     * @param  string  $name
     * @return string The ID of the created author
     */
    public function addAuthor($name);
}

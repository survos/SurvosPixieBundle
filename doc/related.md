# Related Tables


Given a movie CSV table, create a pixie database with related tables

```csv
id,title,poster,overview,release_date,genres
287947,Shazam!,https://image.tmdb.org/t/p/w500/xnopI5Xtky18MPhK40cZAGAOVeV.jpg,"A boy is given the ability to become an adult superhero in times of need with a single magic word.",1553299200,"Action, Comedy, Fantasy"
299537,"Captain Marvel",https://image.tmdb.org/t/p/w500/AtsgWhDnHTq68L0lLsUrCnM7TjG.jpg,"The story follows Carol Danvers as she becomes one of the universe’s most powerful heroes when Earth is caught in the middle of a galactic war between two alien races. Set in the 1990s, Captain Marvel is an all-new adventure from a previously unseen period in the history of the Marvel Cinematic Universe.",1551830400,"Action, Adventure, Science Fiction"
```

Goal: Create movie and genre tables, with genre counts as an extra field

We need a schema to define the output csv database, which we can create by naming the fields.

```php

$Pixie = new Pixie('movie_and_genre');
$grid = (new \Survos\PixieBundle\Model\Grid('movie'));
$grid->addRow()



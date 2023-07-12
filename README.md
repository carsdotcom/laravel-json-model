# Laravel Json Model

We really love Laravel as an ORM. But we have a part of our application that is not backed by a document store,
not a relational database. Json Models let us use the best parts of the Eloquent Models, 
but instead of being backed by a row in a table, they're always serialized to JSON. (Which can include 
being serialized to an attribute on a traditional Laravel Model!)

## Setup

For now, you will need to add the following for events to work properly.
1. In AppServiceProvider boot method: `JsonModel::setEventDispatcher($this->app['events']);`
2. In AppServiceProvider register method: `JsonModel::clearBootedModels();`

# Introduction

Graphql is a query language and so the first thing we will be going through is how can you start making queries to Drupal. One of the great benefits of GraphQL is how intuitive the query syntax and corresponsing responses look. Essentially, the query is a lot like how you want the response to look but without the values. Lets have a look at the example we saw in the introduction:

```graphql
query {
  user: currentUserContext{
    ...on User {
      name
    }
  }
}
```

You can run the the above query in your browser, via a GET request, after enabling the module. Note, if you are logged in already, the query should return a result. If you want the anonymous user to run the following query, you will need to enable the `Execute arbitrary GraphQL requests` permission. You can also run this query in the GraphiQL browser provided with the module at : `/graphql/explorer` \[YOUR DOMAIN\]/graphql?query=query{user:currentUserContext{...on%20User{name}}}

This would return a result similar to:

```javascript
{
  "data": {
    "user": {
      "name": "admin"
    }
  }
}
```

As we can see the data that we get back matches exactly the fields we asked for, in this case the name of the user. In this section we will analyse how we can query against any entity in Drupal. We will be making use of the GraphiQL explorer again, you can test any of the examples here by going to `/graphql/explorer` on your Drupal site.

In this section we will see how to query multiple parts of your drupal backend, and perform queries for nodes, taxonomy, routes etc.

## Naming

GraphQL naming conventions are slightly different than Drupal's.

* Fields and properties are in camelCase. This means that **field\_image** in Drupal becomes **fieldImage** in GraphQL and the **revision\_log** property becomes **revisionLog**.
* Entity types and bundles use camelCase with the first letter capitalized so **taxonomy\_term** becomes **TaxonomyTerm** and the tags vocabulary becomes **TaxonomyTermTags**. As we can see bundles are prefixed with the entity type name. 

This is important so that you know what to expect when searching for fields in queries, however remember what we said that GraphiQL is there to help and so all you need sometimes is a little push by entering "cmd+space" in order to see which fields are available for you to query.

## Fields inside a query

GraphQL has the potential to go and fetch fields from very different places without the need for extra requests, thats one of the benefits of using such a query language. Lets look at this example :

```graphql
query {
  nodeById(id: "1", language: en) {
    entityId
    entityCreated

    title
    status

    ... on NodeArticle {
      fieldSubtitle
    }
  }
}
```

The query above fetches information from 3 different places :

* **entityId** and **entityCreated** come from the Entity Interface. These fields are available for all entity objects. nodeById query returns a Node Interface which extends Entity Interface.
* **title** and **status** are defined in the Node Interface and are available for all nodes, regardless of their content type.
* **fieldSubtitle** is a field \(**field\_subtitle** in Drupal\) that has been added to the Article content type. It's not a part of neither Node nor Entity Interfaces, it is only available in the NodeArticle Type. **nodebyId** can return any node, not just Article, so we need to wrap the fieldSubtitle in a [GraphQL Fragment](http://graphql.org/learn/queries/#fragments).

If we paste the above query in GraphiQL we will get the following result :

```javascript
{
  "data": {
    "nodeById": {
      "entityId": "1",
      "entityCreated": "2017-12-01T00:00:00+0100",
      "title": "GraphQL rocks",
      "status": 1,
      "fieldSubtitle": "Try it out!"
    }
  }
}
```

## Example of all features
```
{
  nodeQuery(filter: {
    conjunction: AND
    conditions: [{
      field: "nid"
      value: ["1", "10"]
      operator: BETWEEN
    }]
    groups: [{
      conjunction: OR
      conditions: [{
        field: "title"
        value: ["graphql filtering rocks"]
      }, {
        field: "title"
        value: ["graphql is awesome"]
        operator: LIKE
      }, {
        field: "uid"
        value: ["1"]
      }],
      groups: [{
        conjunction: AND
        conditions: [{
          field: "field_tags.target_id"
          value: ["1"]
        }]
      }]
    }]
  }, sort: [{
    field: "nid"
    direction: ASC
  }, {
    field: "uid"
    direction: DESC
  }]) {
    entities {
      entityUuid
      entityBundle
      entityPublished
      entityType
      entityChanged
      entityId
      entityCreated
      entityLabel
    }
  }
}
```


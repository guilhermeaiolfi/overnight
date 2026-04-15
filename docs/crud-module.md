# Building a CRUD Module

This guide walks through building a complete CRUD module from scratch using the Overnight framework. We'll build a **Blog** module with posts, categories, and comments.

## Approaches

| Approach | Backend | Frontend | Status |
|----------|---------|----------|--------|
| [GraphQL API](#graphql-api-approach) | GraphQL extension | Vue.js / React | Documented |
| [REST API](#rest-api-approach) | REST API extension | Vue.js / React / Any | Documented |
| Server-rendered | Controller + Views | Plates / Latte | Coming soon |

---

# GraphQL API Approach

This approach uses the GraphQL extension for the backend API and a JavaScript frontend (Vue or React) for the UI. The backend generates the full CRUD schema automatically from your entity definitions — no controllers needed.

## What You'll Build

- **Posts** — list with pagination, filtering, sorting; create/edit/delete
- **Categories** — dropdown for post assignment
- **Comments** — nested under posts
- File upload for post cover images
- Validation on all inputs
- Mutation events for timestamps

---

## Step 1: Define Your Entities

Create your ORM definitions. This is the only backend code you need — the GraphQL extension generates everything else.

```php
// config/orm.php

use ON\ORM\Definition\Registry;

$registry = new Registry();

// Categories
$registry->collection('category')
    ->description('Blog post categories')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('name', 'string')
        ->description('Category name')
        ->validation('required|max:100')
        ->end()
    ->field('slug', 'string')
        ->description('URL-friendly slug')
        ->validation('required|max:100')
        ->end()
    ->end();

// Posts
$registry->collection('post')
    ->description('Blog posts')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('title', 'string')
        ->description('Post title')
        ->validation('required|max:255')
        ->end()
    ->field('content', 'text')
        ->description('Post body content')
        ->end()
    ->field('status', 'string')
        ->description('Publication status')
        ->metadata('enum', ['draft', 'published', 'archived'])
        ->end()
    ->field('cover_image', 'image')
        ->description('Cover image path')
        ->nullable(true)
        ->end()
    ->field('category_id', 'int')
        ->description('Category foreign key')
        ->end()
    ->field('created_at', 'datetime')->nullable(true)->end()
    ->field('updated_at', 'datetime')->nullable(true)->end()
    ->belongsTo('category', 'category')
        ->innerKey('category_id')->outerKey('id')
        ->end()
    ->hasMany('comments', 'comment')
        ->innerKey('id')->outerKey('post_id')
        ->end()
    ->end();

// Comments
$registry->collection('comment')
    ->description('Post comments')
    ->field('id', 'int')->primaryKey(true)->end()
    ->field('post_id', 'int')->end()
    ->field('author', 'string')
        ->validation('required|max:100')
        ->end()
    ->field('body', 'text')
        ->description('Comment text')
        ->validation('required')
        ->end()
    ->field('created_at', 'datetime')->nullable(true)->end()
    ->belongsTo('post', 'post')
        ->innerKey('post_id')->outerKey('id')
        ->end()
    ->end();
```

## Step 2: Install the Extensions

```php
// config/extensions.php

return [
    \ON\Config\ConfigExtension::class => [],
    \ON\Container\ContainerExtension::class => [],
    \ON\Event\EventsExtension::class => [],
    \ON\DB\DatabaseExtension::class => [],
    \ON\Middleware\PipelineExtension::class => [],
    \ON\Router\RouterExtension::class => [],
    \ON\RateLimit\RateLimitExtension::class => [],
    \ON\GraphQL\GraphQLExtension::class => [
        'path' => '/graphql',
        'resolver' => 'auto',
        'introspection' => true,    // set false in production
        'maxDepth' => 10,
        'maxComplexity' => 100,
        'rateLimit' => 100,
        'rateLimitWindow' => 60,
    ],
];
```

That's it for the backend. The GraphQL extension auto-generates:
- Queries: `post`, `post_by_id`, `category`, `category_by_id`, `comment`, `comment_by_id`
- Mutations: `create_post`, `update_post`, `delete_post` (same for category and comment)
- Connection types with `items` and `totalCount` for pagination
- Input types with validation
- Enum type for post status
- Upload scalar for cover images

## Step 3: Add Mutation Events (Optional)

Handle timestamps and file uploads via events:

```php
// src/Blog/BlogModule.php

use ON\Event\EventSubscriberInterface;
use ON\Extension\AbstractExtension;
use ON\GraphQL\Event\BeforeMutation;
use Psr\Http\Message\UploadedFileInterface;

class BlogModule extends AbstractExtension implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'graphql.mutation.before' => 'onBeforeMutation',
        ];
    }

    public function onBeforeMutation(BeforeMutation $event): void
    {
        $input = $event->getInput();
        $operation = $event->getOperation();
        $collection = $event->getCollection()->getName();
        $modified = false;

        // Only handle collections this module owns
        if (!in_array($collection, ['post', 'category', 'comment'])) {
            return;
        }

        // Auto-set timestamps
        if ($operation === 'create') {
            $input['created_at'] = date('Y-m-d H:i:s');
            $input['updated_at'] = date('Y-m-d H:i:s');
            $modified = true;
        } elseif ($operation === 'update') {
            $input['updated_at'] = date('Y-m-d H:i:s');
            $modified = true;
        }

        // Handle file uploads
        foreach ($input as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $filename = uniqid() . '_' . $value->getClientFilename();
                $path = "uploads/{$collection}/{$filename}";
                $value->moveTo($path);
                $input[$key] = $path;
                $modified = true;
            }
        }

        if ($modified) {
            $event->setInput($input);
        }
    }

    // ... standard extension methods
}
```

## Step 4: Frontend Setup

### Install urql

For Vue:
```bash
npm install @urql/vue graphql
npm install @urql/exchange-multipart-fetch  # for file uploads
```

For React:
```bash
npm install urql graphql
npm install @urql/exchange-multipart-fetch
```

### Configure the Client

Vue (`src/main.js`):
```javascript
import { createApp } from 'vue';
import urql, { cacheExchange, fetchExchange } from '@urql/vue';
import { multipartFetchExchange } from '@urql/exchange-multipart-fetch';
import App from './App.vue';

const app = createApp(App);

app.use(urql, {
  url: '/graphql',
  exchanges: [cacheExchange, multipartFetchExchange, fetchExchange],
});

app.mount('#app');
```

React (`src/main.jsx`):
```jsx
import { createClient, cacheExchange, fetchExchange, Provider } from 'urql';
import { multipartFetchExchange } from '@urql/exchange-multipart-fetch';

const client = createClient({
  url: '/graphql',
  exchanges: [cacheExchange, multipartFetchExchange, fetchExchange],
});

root.render(
  <Provider value={client}>
    <App />
  </Provider>
);
```

## Step 5: Frontend — Vue.js Implementation

### GraphQL Operations

```javascript
// src/graphql/posts.js
import { gql } from '@urql/vue';

export const LIST_POSTS = gql`
  query ListPosts($limit: Int, $offset: Int, $sort: String, $order: String, $status: StatusEnum, $title: String) {
    post(limit: $limit, offset: $offset, sort: $sort, order: $order, status: $status, title: $title) {
      items { id title status created_at category { id name } }
      totalCount
    }
  }
`;

export const GET_POST = gql`
  query GetPost($id: ID!) {
    post_by_id(id: $id) {
      id title content status cover_image category_id
      comments { id author body created_at }
    }
  }
`;

export const CREATE_POST = gql`
  mutation CreatePost($input: PostInput!) {
    create_post(input: $input) { id title status }
  }
`;

export const UPDATE_POST = gql`
  mutation UpdatePost($id: ID!, $input: PostUpdateInput!) {
    update_post(id: $id, input: $input) { id title status updated_at }
  }
`;

export const DELETE_POST = gql`
  mutation DeletePost($id: ID!) {
    delete_post(id: $id) { id }
  }
`;

export const LIST_CATEGORIES = gql`
  query ListCategories {
    category { items { id name } totalCount }
  }
`;
```

### Post List View (Vue)

```vue
<!-- src/views/PostList.vue -->
<template>
  <div>
    <h1>Posts</h1>

    <div class="toolbar">
      <input v-model="search" placeholder="Search posts..." @input="debouncedSearch" />
      <select v-model="statusFilter" @change="page = 1">
        <option value="">All statuses</option>
        <option value="DRAFT">Draft</option>
        <option value="PUBLISHED">Published</option>
        <option value="ARCHIVED">Archived</option>
      </select>
      <router-link to="/posts/create">+ New Post</router-link>
    </div>

    <div v-if="fetching">Loading...</div>

    <table v-else>
      <thead>
        <tr>
          <th @click="toggleSort('title')">Title</th>
          <th>Status</th>
          <th>Category</th>
          <th @click="toggleSort('created_at')">Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="post in posts" :key="post.id">
          <td>{{ post.title }}</td>
          <td>{{ post.status }}</td>
          <td>{{ post.category?.name || '—' }}</td>
          <td>{{ formatDate(post.created_at) }}</td>
          <td>
            <router-link :to="`/posts/${post.id}/edit`">Edit</router-link>
            <button @click="removePost(post.id)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="pagination">
      <button :disabled="page <= 1" @click="page--">Previous</button>
      <span>Page {{ page }} of {{ totalPages }}</span>
      <button :disabled="page >= totalPages" @click="page++">Next</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useQuery, useMutation } from '@urql/vue';
import { LIST_POSTS, DELETE_POST } from '../graphql/posts';

const page = ref(1);
const perPage = 20;
const sort = ref('created_at');
const order = ref('DESC');
const search = ref('');
const statusFilter = ref('');

const variables = computed(() => ({
  limit: perPage,
  offset: (page.value - 1) * perPage,
  sort: sort.value,
  order: order.value,
  ...(search.value ? { title: `%${search.value}%` } : {}),
  ...(statusFilter.value ? { status: statusFilter.value } : {}),
}));

const { data, fetching, executeQuery } = useQuery({ query: LIST_POSTS, variables });
const { executeMutation: deletePostMutation } = useMutation(DELETE_POST);

const posts = computed(() => data.value?.post?.items || []);
const totalCount = computed(() => data.value?.post?.totalCount || 0);
const totalPages = computed(() => Math.ceil(totalCount.value / perPage));

function toggleSort(field) {
  if (sort.value === field) {
    order.value = order.value === 'ASC' ? 'DESC' : 'ASC';
  } else {
    sort.value = field;
    order.value = 'ASC';
  }
}

async function removePost(id) {
  if (!confirm('Delete this post?')) return;
  await deletePostMutation({ id: String(id) });
  executeQuery({ requestPolicy: 'network-only' });
}

let searchTimer;
function debouncedSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { page.value = 1; }, 300);
}

function formatDate(d) {
  return d ? new Date(d).toLocaleDateString() : '—';
}
</script>
```

### Post Form (Vue)

```vue
<!-- src/views/PostForm.vue -->
<template>
  <div>
    <h1>{{ isEdit ? 'Edit Post' : 'New Post' }}</h1>

    <form @submit.prevent="save">
      <div class="field">
        <label>Title</label>
        <input v-model="form.title" required />
        <span class="error" v-if="errors.title">{{ errors.title[0] }}</span>
      </div>

      <div class="field">
        <label>Content</label>
        <textarea v-model="form.content" rows="10"></textarea>
      </div>

      <div class="field">
        <label>Status</label>
        <select v-model="form.status">
          <option value="DRAFT">Draft</option>
          <option value="PUBLISHED">Published</option>
          <option value="ARCHIVED">Archived</option>
        </select>
      </div>

      <div class="field">
        <label>Category</label>
        <select v-model="form.category_id">
          <option value="">— Select —</option>
          <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
        </select>
      </div>

      <div class="field">
        <label>Cover Image</label>
        <input type="file" @change="coverFile = $event.target.files[0]" accept="image/*" />
      </div>

      <button type="submit" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      <button type="button" @click="$router.back()">Cancel</button>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useQuery, useMutation } from '@urql/vue';
import { GET_POST, CREATE_POST, UPDATE_POST, LIST_CATEGORIES } from '../graphql/posts';

const route = useRoute();
const router = useRouter();
const isEdit = computed(() => !!route.params.id);

const form = ref({ title: '', content: '', status: 'DRAFT', category_id: '' });
const coverFile = ref(null);
const errors = ref({});
const saving = ref(false);

const { data: catData } = useQuery({ query: LIST_CATEGORIES });
const categories = computed(() => catData.value?.category?.items || []);

const { executeMutation: createPost } = useMutation(CREATE_POST);
const { executeMutation: updatePost } = useMutation(UPDATE_POST);

onMounted(async () => {
  if (isEdit.value) {
    const { data } = await useQuery({ query: GET_POST, variables: { id: route.params.id } });
    if (data.value?.post_by_id) {
      form.value = { ...data.value.post_by_id };
    }
  }
});

async function save() {
  saving.value = true;
  errors.value = {};

  const input = { ...form.value };
  delete input.id;
  delete input.cover_image;
  delete input.comments;
  if (input.category_id) input.category_id = Number(input.category_id);

  try {
    let result;
    if (isEdit.value) {
      result = await updatePost({ id: route.params.id, input });
    } else {
      result = await createPost({ input });
    }

    if (result.error) {
      const ext = result.error.graphQLErrors?.[0]?.extensions;
      if (ext?.validationErrors) {
        errors.value = ext.validationErrors;
        return;
      }
      throw result.error;
    }

    router.push('/posts');
  } catch (err) {
    alert(err.message);
  } finally {
    saving.value = false;
  }
}
</script>
```

## Step 6: React Implementation

### Post List (React)

```jsx
import { useState } from 'react';
import { useQuery, useMutation } from 'urql';
import { LIST_POSTS, DELETE_POST } from '../graphql/posts';

export default function PostList() {
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState('created_at');
  const [order, setOrder] = useState('DESC');
  const [search, setSearch] = useState('');
  const perPage = 20;

  const [result, reexecute] = useQuery({
    query: LIST_POSTS,
    variables: {
      limit: perPage,
      offset: (page - 1) * perPage,
      sort,
      order,
      ...(search ? { title: `%${search}%` } : {}),
    },
  });

  const [, deletePost] = useMutation(DELETE_POST);

  const posts = result.data?.post?.items || [];
  const totalCount = result.data?.post?.totalCount || 0;
  const totalPages = Math.ceil(totalCount / perPage);

  const handleSort = (field) => {
    if (sort === field) setOrder(o => o === 'ASC' ? 'DESC' : 'ASC');
    else { setSort(field); setOrder('ASC'); }
  };

  const handleDelete = async (id) => {
    if (!confirm('Delete?')) return;
    await deletePost({ id: String(id) });
    reexecute({ requestPolicy: 'network-only' });
  };

  if (result.fetching) return <p>Loading...</p>;

  return (
    <div>
      <h1>Posts</h1>
      <input value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} placeholder="Search..." />
      <a href="/posts/create">+ New Post</a>

      <table>
        <thead>
          <tr>
            <th onClick={() => handleSort('title')}>Title</th>
            <th>Status</th>
            <th>Category</th>
            <th onClick={() => handleSort('created_at')}>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {posts.map(post => (
            <tr key={post.id}>
              <td>{post.title}</td>
              <td>{post.status}</td>
              <td>{post.category?.name || '—'}</td>
              <td>{post.created_at}</td>
              <td>
                <a href={`/posts/${post.id}/edit`}>Edit</a>
                <button onClick={() => handleDelete(post.id)}>Delete</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <button disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
      <span>Page {page} of {totalPages}</span>
      <button disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>Next</button>
    </div>
  );
}
```

## Step 7: Error Handling

urql returns errors in the result object. Handle validation errors from the GraphQL extension:

```javascript
// Vue
const result = await createPost({ input });
if (result.error) {
  const ext = result.error.graphQLErrors?.[0]?.extensions;
  if (ext?.code === 'VALIDATION_ERROR') {
    errors.value = ext.validationErrors;
  } else if (ext?.code === 'DUPLICATE') {
    alert(result.error.message);
  }
}

// React
const [result, execute] = useMutation(CREATE_POST);
if (result.error) {
  const ext = result.error.graphQLErrors?.[0]?.extensions;
  // same pattern
}
```

## Summary

| Layer | What You Write | What's Auto-Generated |
|-------|---------------|----------------------|
| Backend entities | `config/orm.php` — field definitions, validation, relations | GraphQL schema, queries, mutations, input types, enums |
| Backend events | `BlogModule.php` — timestamps, file uploads (optional) | Event dispatching in mutation lifecycle |
| Frontend client | urql setup in `main.js` / `main.jsx` | Loading states, caching, error objects |
| Frontend views | Vue/React components with `useQuery` / `useMutation` | — |

The backend is ~50 lines of entity definitions. Everything else — schema generation, CRUD operations, pagination, filtering, sorting, validation, error handling, file uploads — is handled by the framework.

---

# REST API Approach

This approach uses the REST API extension for the backend. The same entity definitions generate a full Directus-style REST interface — no GraphQL, no controllers, just standard HTTP endpoints. The frontend uses plain `fetch()` calls.

## What's Different from GraphQL

| | GraphQL | REST API |
|---|---------|----------|
| Endpoint | Single `/graphql` | One per collection: `/items/post`, `/items/user` |
| Query language | GraphQL queries/mutations | HTTP methods + query parameters |
| Field selection | GraphQL field selection | `?fields=id,title,author.name` |
| Filtering | GraphQL arguments | `?filter[status][_eq]=published` |
| Frontend library | urql / Apollo | Plain `fetch()` or any HTTP client |
| Learning curve | Need to learn GraphQL | Standard REST — familiar to everyone |
| Aggregation | Not built-in | `?aggregate[count]=id&groupBy[]=status` |
| Schema introspection | Built-in (`__schema`) | Via SchemaAddon (`/_schema`) |

## Step 1: Define Your Entities

Same entity definitions as the GraphQL approach — the ORM registry is shared:

```php
// config/orm.php (identical to GraphQL approach)
// See Step 1 in the GraphQL section above
```

## Step 2: Install the Extension

```php
// config/extensions.php

use ON\RestApi\RestApiExtension;
use ON\RestApi\Addon\RevisionAddon;
use ON\RestApi\Addon\SchemaAddon;

return [
    \ON\Config\ConfigExtension::class => [],
    \ON\Container\ContainerExtension::class => [],
    \ON\Event\EventsExtension::class => [],
    \ON\DB\DatabaseExtension::class => [],
    \ON\Middleware\PipelineExtension::class => [],
    \ON\RateLimit\RateLimitExtension::class => [],
    RestApiExtension::class => [
        'path' => '/items',
        'defaultLimit' => 50,
        'maxLimit' => 500,
        'rateLimit' => 100,
        'addons' => [
            SchemaAddon::class,
            RevisionAddon::class => ['table' => 'revisions'],
        ],
    ],
];
```

That's it. You now have:

```
GET    /items/post                → List posts
GET    /items/post/1              → Get post by ID
POST   /items/post                → Create post
PATCH  /items/post/1              → Update post
DELETE /items/post/1              → Delete post
GET    /items/_schema             → List all collections
GET    /items/_schema/post        → Get post schema
```

Same for `category`, `comment`, and every other collection.

## Step 3: Add Events (Optional)

Handle timestamps and file uploads via event listeners:

```php
// src/Blog/BlogEventSubscriber.php

use ON\Event\EventSubscriberInterface;
use ON\RestApi\Event\ItemCreate;
use ON\RestApi\Event\ItemUpdate;
use ON\RestApi\Event\FileUpload;

class BlogEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'restapi.item.create' => [0, 'onItemCreate'],
            'restapi.item.update' => [0, 'onItemUpdate'],
            'restapi.file.upload' => [0, 'onFileUpload'],
        ];
    }

    public function onItemCreate(ItemCreate $event): void
    {
        $collection = $event->getCollection()->getName();
        if (!in_array($collection, ['post', 'category', 'comment'])) {
            return;
        }

        $input = $event->getInput();
        $input['created_at'] = date('Y-m-d H:i:s');
        $input['updated_at'] = date('Y-m-d H:i:s');
        $event->setInput($input);
    }

    public function onItemUpdate(ItemUpdate $event): void
    {
        $collection = $event->getCollection()->getName();
        if (!in_array($collection, ['post', 'category', 'comment'])) {
            return;
        }

        $input = $event->getInput();
        $input['updated_at'] = date('Y-m-d H:i:s');
        $event->setInput($input);
    }

    public function onFileUpload(FileUpload $event): void
    {
        $file = $event->getFile();
        $collection = $event->getCollection()->getName();
        $ext = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $name = bin2hex(random_bytes(16)) . '.' . $ext;

        $path = "uploads/{$collection}/{$name}";
        $file->moveTo("public/{$path}");

        $event->setStoredPath($path);
        $event->preventDefault();
    }
}
```

## Step 4: Frontend — Plain JavaScript

No special library needed. Just `fetch()`.

### API Helper

```javascript
// src/api.js
const BASE = '/items';

export const api = {
  async list(collection, params = {}) {
    const query = new URLSearchParams();
    if (params.limit) query.set('limit', params.limit);
    if (params.offset) query.set('offset', params.offset);
    if (params.page) query.set('page', params.page);
    if (params.sort) query.set('sort', params.sort);
    if (params.search) query.set('search', params.search);
    if (params.fields) query.set('fields', params.fields);
    if (params.meta) query.set('meta', params.meta);

    // Filters: { status: { _eq: 'published' } }
    if (params.filter) {
      for (const [field, ops] of Object.entries(params.filter)) {
        for (const [op, val] of Object.entries(ops)) {
          query.set(`filter[${field}][${op}]`, val);
        }
      }
    }

    const res = await fetch(`${BASE}/${collection}?${query}`);
    return res.json();
  },

  async get(collection, id, params = {}) {
    const query = new URLSearchParams();
    if (params.fields) query.set('fields', params.fields);
    const res = await fetch(`${BASE}/${collection}/${id}?${query}`);
    return res.json();
  },

  async create(collection, data) {
    const res = await fetch(`${BASE}/${collection}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    return res.json();
  },

  async update(collection, id, data) {
    const res = await fetch(`${BASE}/${collection}/${id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    return res.json();
  },

  async remove(collection, id) {
    return fetch(`${BASE}/${collection}/${id}`, { method: 'DELETE' });
  },

  async aggregate(collection, params = {}) {
    const query = new URLSearchParams();
    if (params.aggregate) {
      for (const [func, field] of Object.entries(params.aggregate)) {
        query.set(`aggregate[${func}]`, field);
      }
    }
    if (params.groupBy) {
      params.groupBy.forEach((f, i) => query.set(`groupBy[${i}]`, f));
    }
    const res = await fetch(`${BASE}/${collection}?${query}`);
    return res.json();
  },
};
```

### Post List (Vue)

```vue
<template>
  <div>
    <h1>Posts</h1>

    <div class="toolbar">
      <input v-model="search" placeholder="Search posts..." @input="debouncedSearch" />
      <select v-model="statusFilter" @change="page = 1; loadPosts()">
        <option value="">All statuses</option>
        <option value="draft">Draft</option>
        <option value="published">Published</option>
        <option value="archived">Archived</option>
      </select>
      <router-link to="/posts/create">+ New Post</router-link>
    </div>

    <div v-if="loading">Loading...</div>

    <table v-else>
      <thead>
        <tr>
          <th @click="toggleSort('title')">Title</th>
          <th>Status</th>
          <th>Category</th>
          <th @click="toggleSort('created_at')">Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="post in posts" :key="post.id">
          <td>{{ post.title }}</td>
          <td>{{ post.status }}</td>
          <td>{{ post.category?.name || '—' }}</td>
          <td>{{ formatDate(post.created_at) }}</td>
          <td>
            <router-link :to="`/posts/${post.id}/edit`">Edit</router-link>
            <button @click="removePost(post.id)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="pagination">
      <button :disabled="page <= 1" @click="page--; loadPosts()">Previous</button>
      <span>Page {{ page }} of {{ totalPages }}</span>
      <button :disabled="page >= totalPages" @click="page++; loadPosts()">Next</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { api } from '../api';

const posts = ref([]);
const totalCount = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = 20;
const sort = ref('-created_at');
const search = ref('');
const statusFilter = ref('');

const totalPages = computed(() => Math.ceil(totalCount.value / perPage));

async function loadPosts() {
  loading.value = true;
  const filter = {};
  if (statusFilter.value) filter.status = { _eq: statusFilter.value };

  const result = await api.list('post', {
    limit: perPage,
    page: page.value,
    sort: sort.value,
    search: search.value || undefined,
    filter: Object.keys(filter).length ? filter : undefined,
    fields: 'id,title,status,created_at,category.name',
    meta: 'total_count',
  });

  posts.value = result.data;
  totalCount.value = result.meta?.total_count || 0;
  loading.value = false;
}

function toggleSort(field) {
  sort.value = sort.value === field ? `-${field}` : field;
  loadPosts();
}

async function removePost(id) {
  if (!confirm('Delete this post?')) return;
  await api.remove('post', id);
  loadPosts();
}

let searchTimer;
function debouncedSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { page.value = 1; loadPosts(); }, 300);
}

function formatDate(d) {
  return d ? new Date(d).toLocaleDateString() : '—';
}

onMounted(loadPosts);
</script>
```

### Post Form (Vue)

```vue
<template>
  <div>
    <h1>{{ isEdit ? 'Edit Post' : 'New Post' }}</h1>

    <form @submit.prevent="save">
      <div class="field">
        <label>Title</label>
        <input v-model="form.title" required />
        <span class="error" v-if="errors.title">{{ errors.title[0] }}</span>
      </div>

      <div class="field">
        <label>Content</label>
        <textarea v-model="form.content" rows="10"></textarea>
      </div>

      <div class="field">
        <label>Status</label>
        <select v-model="form.status">
          <option value="draft">Draft</option>
          <option value="published">Published</option>
          <option value="archived">Archived</option>
        </select>
      </div>

      <div class="field">
        <label>Category</label>
        <select v-model="form.category_id">
          <option value="">— Select —</option>
          <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
        </select>
      </div>

      <button type="submit" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      <button type="button" @click="$router.back()">Cancel</button>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';

const route = useRoute();
const router = useRouter();
const isEdit = computed(() => !!route.params.id);

const form = ref({ title: '', content: '', status: 'draft', category_id: '' });
const categories = ref([]);
const errors = ref({});
const saving = ref(false);

onMounted(async () => {
  const catResult = await api.list('category', { fields: 'id,name' });
  categories.value = catResult.data;

  if (isEdit.value) {
    const result = await api.get('post', route.params.id);
    form.value = { ...result.data };
  }
});

async function save() {
  saving.value = true;
  errors.value = {};

  const data = { ...form.value };
  if (data.category_id) data.category_id = Number(data.category_id);

  try {
    let result;
    if (isEdit.value) {
      delete data.id;
      result = await api.update('post', route.params.id, data);
    } else {
      result = await api.create('post', data);
    }

    if (result.errors) {
      const ext = result.errors[0]?.extensions;
      if (ext?.validationErrors) {
        errors.value = ext.validationErrors;
        return;
      }
      throw new Error(result.errors[0].message);
    }

    router.push('/posts');
  } catch (err) {
    alert(err.message);
  } finally {
    saving.value = false;
  }
}
</script>
```

## Step 5: Dashboard with Aggregation

The REST API supports aggregation queries — perfect for dashboards:

```javascript
// Load dashboard stats
const [postsByStatus, totalPosts, recentActivity] = await Promise.all([
  api.aggregate('post', {
    aggregate: { count: 'id' },
    groupBy: ['status'],
  }),
  api.aggregate('post', {
    aggregate: { count: 'id' },
  }),
  api.list('post', {
    sort: '-updated_at',
    limit: 5,
    fields: 'id,title,status,updated_at',
  }),
]);

// postsByStatus.data → [{ status: 'published', count: { id: 42 } }, ...]
// totalPosts.data → [{ count: { id: 50 } }]
// recentActivity.data → [{ id: 1, title: '...', ... }, ...]
```

## Step 6: Error Handling

REST API errors follow a consistent format:

```javascript
const result = await api.create('post', data);

if (result.errors) {
  const error = result.errors[0];
  const code = error.extensions?.code;

  switch (code) {
    case 'VALIDATION_ERROR':
      // Per-field errors
      errors.value = error.extensions.validationErrors;
      break;
    case 'DUPLICATE':
      alert(`Duplicate: ${error.message}`);
      break;
    case 'NOT_FOUND':
      router.push('/404');
      break;
    default:
      alert(error.message);
  }
}
```

## Comparison: GraphQL vs REST API

| Feature | GraphQL | REST API |
|---------|---------|----------|
| Backend setup | Same entity definitions | Same entity definitions |
| Frontend complexity | Need urql/Apollo + GraphQL queries | Plain `fetch()` + query params |
| Field selection | Built into GraphQL | `?fields=id,title,author.name` |
| Nested relations | GraphQL nested queries | Dot notation in `fields` param |
| Aggregation | Not built-in | `?aggregate[count]=id&groupBy[]=status` |
| Batch operations | Single mutation per call | POST/PATCH/DELETE with arrays |
| Caching | urql/Apollo cache | ETag + `If-None-Match` |
| Schema discovery | GraphQL introspection | SchemaAddon (`/_schema`) |
| Revision history | Via events | RevisionAddon (built-in) |
| File uploads | Multipart spec + Upload scalar | Standard multipart/form-data |
| Rate limiting | Via extension | Via extension |

Both approaches share the same entity definitions, validation rules, and event system. Choose GraphQL for complex nested queries and type safety, REST for simplicity and standard HTTP tooling.

---

## See Also

- [GraphQL Extension](extensions/graphql.md) — Full GraphQL API reference
- [REST API Extension](extensions/rest-api.md) — Full REST API reference with filter operators, aggregation, addons
- [ORM Entity Definition](orm-entity-definition.md) — Field types, relations, metadata
- [Events Extension](extensions/events.md) — Event subscribers and listeners
- [DataLoader](extensions/graphql-dataloader.md) — N+1 problem solutions (GraphQL)